<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# OpenAI-compatible API

Serves an OpenAI-compatible HTTP API backed by the Nextcloud Task Processing
API, so third party clients that speak the OpenAI dialect can use whichever AI
providers the administrator configured for this instance.

## Using it

Point the client at the instance and use a Nextcloud app password as the API key:

```
OPENAI_BASE_URL=https://cloud.example.com/index.php/apps/openai_api/v1
OPENAI_API_KEY=<a Nextcloud app password>
```

App passwords are accepted through `Authorization: Bearer`, which is what every
OpenAI client sends, so no extra configuration is needed. Tasks are scheduled on
behalf of the authenticated user and obey the same provider selection, task type
availability and guest restrictions as any other Task Processing consumer.

The endpoints are exempt from the CSRF token check, since a client speaking the
OpenAI dialect has no way to obtain one. That exemption only covers requests
that authenticate with credentials: a request that arrives on a browser session
instead still has to carry a CSRF token to write anything, so a page on another
site cannot drive the API with a logged-in user's cookie.

## Endpoints

| Endpoint | Task type |
| --- | --- |
| `GET /v1/models`, `GET /v1/models/{model}` | the task types below, filtered to those available to the user |
| `POST /v1/chat/completions` | `core:text2text:multimodal-chatwithtools`, `core:text2text:chatwithtools`, `core:text2text:chat`, `core:text2text` |
| `POST /v1/completions` | `core:text2text` |
| `POST /v1/audio/transcriptions` | `core:audio2text` |
| `POST /v1/audio/speech` | `core:text2speech` |
| `POST /v1/images/generations` | `core:text2image` |

### Models are task types

A model id is a task type id, for example `core:text2text:chatwithtools`. The
concrete model behind it is whichever provider the administrator prefers for
that task type - that is the axis a client can meaningfully choose along, since
providers are selected per task type instance-wide.

Omitting `model` picks the most capable chat task type that is available.

Where a task type cannot carry part of a request the request is rejected rather
than quietly degraded: asking `core:text2text:chat` for tools, or a non
multimodal task type for image attachments, returns a `400` naming the task type
to use instead. The conversation itself is the exception - for `core:text2text`,
which has no `history` slot, the turns are flattened into a single prompt.

## Streaming

`"stream": true` is answered with a `text/event-stream` in either of two modes,
selected by the administrator under Administration settings → Artificial
Intelligence:

- **Progressive** (default): the task is scheduled and its output is forwarded as
  the provider produces it. Providers report intermediate output cumulatively and
  Task Processing persists it at most once every two seconds, so chunks arrive at
  roughly that cadence rather than per token. Each open stream occupies a PHP
  worker for the duration of the response.
- **Single chunk**: the request runs to completion and the answer is emitted as
  one content chunk followed by `[DONE]`. The event stream is still well formed,
  and a worker is not held open for a polling loop.

### Progressive streaming needs a task processing worker

A provider that runs inside the web request (`ISynchronousProvider`, which is
what `integration_openai` and similar PHP apps register) cannot be polled while
it works, so progressively streamed requests schedule the task and wait for
something else to execute it. On a stock instance that is cron, which runs every
five minutes - far too slow for interactive chat.

Run a worker as a service:

```
occ taskprocessing:worker
```

Providers served by an ex-app are unaffected: they pick scheduled tasks up
themselves. The admin settings page warns when the preferred chat provider is
synchronous and progressive streaming is on.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `real_streaming` | `true` | Whether streaming requests are forwarded progressively. Exposed in the admin UI. |
| `request_timeout` | `300` | Seconds to wait for a task before cancelling it. |
| `poll_interval_ms` | `500` | Milliseconds between task polls while streaming. |
| `staged_file_ttl` | `3600` | Seconds after which staged input files of abandoned requests are deleted. |
| `max_concurrent_requests` | `5` | How many requests of one user may occupy a PHP worker at once. `0` disables the limit. |
| `max_attachment_size` | `52428800` | Maximum size in bytes of one attachment, and of all attachments of a request together. |

```
occ config:app:set openai_api real_streaming --value=0 --type=boolean
```

### Limits

Every request drives a task to a terminal state inside the request, so it holds
a PHP worker until the task finishes or `request_timeout` is reached. Two limits
keep one account from occupying the whole worker pool:

- Each endpoint is rate limited per user - 60 requests a minute for chat and
  completions, 30 for the audio endpoints, 10 for image generation.
- `max_concurrent_requests` caps how many requests of one user may be in flight
  at the same time; further requests are answered with `429` until one finishes.
  The slots live in the distributed cache, so the cap only applies when one is
  configured.

## Attachments

Task Processing resolves file slots through the user mount cache, so attachments
cannot live in appdata. Inline attachments on the final turn of a conversation,
and audio uploaded to `/v1/audio/transcriptions`, are written to a hidden
`.openai_api` folder in the user's root and deleted once the request is done.
Files left behind by a request that died are swept on that user's next request;
the sweep only touches files this app staged, so a folder a user happens to keep
under that name does not lose its contents. Desktop and mobile clients will sync
the folder while files are in it.

The path has to be a folder in the user's own storage. If a share or a group
folder is mounted there the request is rejected rather than staging attachments
where somebody else can read them. Attachments are limited to
`max_attachment_size` each and in total.

Attachments in earlier turns of a conversation are passed through to the provider
as OpenAI content parts and are not staged.

## Development

The app builds on its own. Clone it into the `apps/` directory of a Nextcloud
server checkout - the PHP tests boot that server to get the OCP implementation
and the `Test\TestCase` base class.

```
make dev          # composer install + npm ci + npm run build (development)
make build        # the same, with a production frontend build
make lint         # php -l, php-cs-fixer, psalm, eslint, stylelint
make test         # phpunit
make appstore     # release tarball (requires krankerl)
```

The individual steps are also available as `composer run {lint,cs:check,cs:fix,psalm,test:unit}`
and `npm run {build,dev,watch,lint,stylelint}`. The frontend build writes
`js/openai_api-settings-admin.mjs`, which is what the admin settings page loads.

Node 24 is required, as for the server itself - `@nextcloud/eslint-config` uses
APIs that older releases do not have.

There is no `openapi.json`: the surface here is the OpenAI dialect, not the
Nextcloud OCS API, so there is nothing to generate or keep in sync.

## Known gaps

- **No `/v1/embeddings`**: there is no embeddings task type in Task Processing,
  so there is nothing to back the endpoint with.
- **Remote attachment URLs are rejected**: only inline `data:` URLs are accepted,
  which is what clients send in practice. Fetching remote URLs would add an
  outbound request to a public endpoint and is not implemented.
- **`usage` is always zero**: Task Processing does not report token counts. The
  field is present because clients read it unconditionally.
- **`/v1/images/generations` defaults to `b64_json`** rather than OpenAI's `url`,
  because a URL would point back at an authenticated Nextcloud route that clients
  do not send credentials to. Pass `"response_format": "url"` to get one anyway.
- **`/v1/completions` always emits a single chunk** when streaming, regardless of
  the streaming setting.
- **Ignored parameters**: `temperature`, `top_p`, `n`, `stop`, `tool_choice`,
  `seed` and friends have no Task Processing slot and are accepted but dropped.
  `n` is the exception on `/v1/images/generations`, where it selects the number
  of images and is capped at 10 as it is upstream.
  `max_tokens` / `max_completion_tokens` are forwarded when the provider declares
  the slot.
