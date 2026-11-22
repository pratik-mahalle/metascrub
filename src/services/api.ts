/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios, { isAxiosError } from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

export type FindingType = 'location' | 'camera' | 'serial' | 'datetime' | 'software' | 'author'
	| 'thumbnail' | 'makernote' | 'xmp' | 'iptc' | 'comment' | 'text' | 'hidden' | 'other'

export interface Finding {
	type: FindingType
	details: string[]
}

export interface Report {
	format: 'jpeg' | 'png' | 'webp'
	clean: boolean
	findings: Finding[]
	removedBytes: number
	orientationKept: boolean
}

export interface ScrubResult extends Report {
	changed: boolean
	size: number
	mtime: number
	etag: string
}

interface OcsResponse<T> {
	ocs: { meta: { status: string, message?: string }, data: T }
}

const url = (fileId: number, action = '') => generateOcsUrl('/apps/metascrub/api/v1/files/{fileId}' + action, { fileId })

/**
 * Show which metadata a file contains, without changing it.
 *
 * @param fileId Nextcloud file id
 */
export async function analyze(fileId: number): Promise<Report> {
	try {
		const { data } = await axios.get<OcsResponse<Report>>(url(fileId))
		return data.ocs.data
	} catch (error) {
		throw new Error(messageFrom(error))
	}
}

/**
 * Remove metadata from a file. The original stays in the version history.
 *
 * @param fileId Nextcloud file id
 */
export async function scrub(fileId: number): Promise<ScrubResult> {
	try {
		const { data } = await axios.post<OcsResponse<ScrubResult>>(url(fileId, '/scrub'))
		return data.ocs.data
	} catch (error) {
		throw new Error(messageFrom(error))
	}
}

/**
 * Pull the server's translated message out of an OCS error.
 *
 * @param error What axios threw
 */
function messageFrom(error: unknown): string {
	if (isAxiosError<OcsResponse<{ message?: string }>>(error)) {
		const message = error.response?.data?.ocs?.data?.message
		if (message) {
			return message
		}
	}
	return t('metascrub', 'Something went wrong. Please try again.')
}
