/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { INode } from '@nextcloud/files'
import type { ScrubResult } from '../services/api.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { formatFileSize } from '@nextcloud/files'
import { n, t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import ScrubDialog from '../components/ScrubDialog.vue'
import { logger } from '../logger.ts'
import { scrub } from '../services/api.ts'
import { canEdit } from './scrubAction.ts'

const BATCH_CONCURRENCY = 3

/**
 * Reflect the new size / mtime / etag in the file list without a reload.
 *
 * @param node The file that was cleaned
 * @param result What the server returned
 */
function updateNode(node: INode, result: ScrubResult) {
	node.size = result.size
	node.mtime = new Date(result.mtime * 1000)
	node.update({ etag: result.etag })
	emit('files:node:updated', node)
}

/**
 * Show what a file reveals and let the user remove it.
 *
 * @param node The selected file
 */
export async function scrubOne(node: INode): Promise<void> {
	const result = await spawnDialog(ScrubDialog, {
		fileId: node.fileid!,
		fileName: node.displayname,
		canEdit: canEdit(node),
	})

	if (result?.changed) {
		updateNode(node, result)
		showSuccess(t('metascrub', 'Metadata removed from {name} ({size} saved)', {
			name: node.displayname,
			size: formatFileSize(result.removedBytes),
		}))
	}
}

/**
 * Clean several files, a few at a time, then show one summary.
 *
 * @param nodes The selected files
 */
export async function scrubMany(nodes: INode[]): Promise<void> {
	let cleaned = 0
	let alreadyClean = 0
	const failures: string[] = []

	let next = 0
	const worker = async () => {
		while (next < nodes.length) {
			const node = nodes[next++]
			try {
				const result = await scrub(node.fileid!)
				if (result.changed) {
					updateNode(node, result)
					cleaned++
				} else {
					alreadyClean++
				}
			} catch (error) {
				logger.error('Could not remove metadata', { error, fileid: node.fileid })
				failures.push((error as Error).message)
			}
		}
	}
	await Promise.all(Array.from({ length: Math.min(BATCH_CONCURRENCY, nodes.length) }, worker))

	const parts: string[] = []
	if (cleaned > 0) {
		parts.push(n('metascrub', 'Metadata removed from %n file', 'Metadata removed from %n files', cleaned))
	}
	if (alreadyClean > 0) {
		parts.push(n('metascrub', '%n file was already clean', '%n files were already clean', alreadyClean))
	}
	if (parts.length > 0) {
		showSuccess(parts.join('. '))
	}
	if (failures.length > 0) {
		showError(n('metascrub', '%n file could not be cleaned: {message}', '%n files could not be cleaned: {message}', failures.length, { message: failures[0] }))
	}
}
