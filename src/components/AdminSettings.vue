<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcSettingsSection
		:name="t('openai_api', 'OpenAI-compatible API')"
		:description="t('openai_api', 'Lets clients that speak the OpenAI API use the AI providers configured for this instance. Point the client at the base URL below and use a Nextcloud app password as the API key.')">
		<NcNoteCard type="info">
			<code>{{ baseUrl }}</code>
		</NcNoteCard>

		<NcCheckboxRadioSwitch v-model="realStreaming" type="switch">
			{{ t('openai_api', 'Stream responses progressively') }}
		</NcCheckboxRadioSwitch>
		<p class="openai-api-settings__hint">
			{{ t('openai_api', 'When enabled, responses to streaming requests are forwarded as the provider produces them. Each open stream occupies a PHP worker until the response is complete. When disabled, streaming requests are answered with the full response in a single chunk instead.') }}
		</p>

		<NcNoteCard v-if="needsWorker && realStreaming" type="warning">
			{{ t('openai_api', 'The preferred provider for chat runs inside the web request and cannot be polled while it works. Progressive streaming therefore waits for a background worker to pick the task up. Run "occ taskprocessing:worker" as a service, or turn progressive streaming off.') }}
		</NcNoteCard>

		<NcNoteCard v-if="concurrencyLimit.status === 'no_cache'" type="warning">
			{{ t('openai_api', 'The per-user limit on concurrent requests is not enforced because no distributed memory cache is configured. Every request occupies a PHP worker until its task finishes, so a single account can exhaust the worker pool. Configure a distributed cache such as Redis ("memcache.distributed") to enable the limit.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="concurrencyLimit.status === 'disabled'" type="warning">
			{{ t('openai_api', 'The per-user limit on concurrent requests is turned off. Every request occupies a PHP worker until its task finishes, so a single account can exhaust the worker pool. Set it with "occ config:app:set openai_api max_concurrent_requests --value=5 --type=integer".') }}
		</NcNoteCard>
		<p v-else class="openai-api-settings__hint">
			{{ n('openai_api', 'Each user can have at most %n request in flight at a time.', 'Each user can have at most %n requests in flight at a time.', concurrencyLimit.limit) }}
		</p>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateOcsUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { logger } from '../logger.ts'

export default {
	name: 'AdminSettings',
	components: {
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSettingsSection,
	},

	setup() {
		return { n, t }
	},

	data() {
		return {
			realStreaming: loadState('openai_api', 'realStreaming'),
			baseUrl: loadState('openai_api', 'baseUrl'),
			needsWorker: loadState('openai_api', 'needsWorker'),
			concurrencyLimit: loadState('openai_api', 'concurrencyLimit'),
		}
	},

	watch: {
		realStreaming(newValue) {
			this.update('real_streaming', newValue)
		},
	},

	methods: {
		async update(key, value) {
			await confirmPassword()
			const url = generateOcsUrl('/apps/provisioning_api/api/v1/config/apps/{appId}/{key}', {
				appId: 'openai_api',
				key,
			})
			try {
				await axios.post(url, { value: value ? '1' : '0' })
			} catch (error) {
				showError(t('openai_api', 'Unable to update the OpenAI-compatible API config'))
				logger.error('Unable to update the OpenAI-compatible API config', { error })
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.openai-api-settings__hint {
	margin-block: 4px 12px;
	color: var(--color-text-maxcontrast);
}
</style>
