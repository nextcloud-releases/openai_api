<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Trashbin\Trash;

/**
 * The part of the files_trashbin app this app uses. files_trashbin is optional
 * and not part of the public API, so it is only stubbed for static analysis.
 */
interface ITrashManager {
	/**
	 * Temporarily disable the trash bin, deleted files are removed for good.
	 */
	public function pauseTrash();

	/**
	 * Re-enable the trash bin after pausing it.
	 */
	public function resumeTrash();
}
