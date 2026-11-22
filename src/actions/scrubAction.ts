/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { IFileAction, INode } from '@nextcloud/files'

import MapMarkerOffSvg from '@mdi/svg/svg/map-marker-off-outline.svg?raw'
import { getCurrentUser } from '@nextcloud/auth'
import { FileType, Permission } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'

// This file is part of the init script that loads with every Files page, so it
// only holds what the Files app needs to decide whether to show the action.
// The actual work (dialogs, HTTP, Vue) lives in ./run.ts and is lazy-loaded.

export const ACTION_ID = 'metascrub-remove-metadata'

const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp']

const isSupported = (node: INode) => node.type === FileType.File
	&& node.fileid !== undefined
	&& SUPPORTED_MIMES.includes(node.mime)
	&& (node.permissions & Permission.READ) !== 0

export const canEdit = (node: INode) => (node.permissions & Permission.UPDATE) !== 0

export const scrubAction: IFileAction = {
	id: ACTION_ID,
	displayName: () => t('metascrub', 'Remove metadata'),
	title: () => t('metascrub', 'Remove location, camera details and other hidden metadata'),
	iconSvgInline: () => MapMarkerOffSvg,
	order: 60,

	enabled({ nodes, view }) {
		if (getCurrentUser() === null || view.id === 'trashbin' || nodes.length === 0) {
			return false
		}
		if (!nodes.every(isSupported)) {
			return false
		}
		// A single file can be inspected read-only; batches need write access.
		return nodes.length === 1 || nodes.every(canEdit)
	},

	async exec({ nodes: [node] }) {
		const { scrubOne } = await import('./run.ts')
		await scrubOne(node)
		// All feedback is shown by us, so tell the Files app to stay silent.
		return null
	},

	async execBatch({ nodes }) {
		const { scrubMany } = await import('./run.ts')
		await scrubMany(nodes)
		return nodes.map(() => null)
	},
}
