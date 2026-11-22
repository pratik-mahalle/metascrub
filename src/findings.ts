/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FindingType } from './services/api.ts'

import {
	mdiAccountOutline,
	mdiCalendarClock,
	mdiCameraOutline,
	mdiCodeTags,
	mdiCommentTextOutline,
	mdiEyeOffOutline,
	mdiFactory,
	mdiFormatText,
	mdiImageMultipleOutline,
	mdiMapMarkerOutline,
	mdiNewspaperVariantOutline,
	mdiNumeric,
	mdiPencilOutline,
	mdiTagOutline,
} from '@mdi/js'
import { t } from '@nextcloud/l10n'

interface FindingInfo {
	label: string
	icon: string
	/** Higher = more sensitive, shown first */
	weight: number
}

/**
 * Translated label and icon for each kind of metadata the backend reports.
 *
 * @param type Finding type from the API
 */
export function describe(type: FindingType): FindingInfo {
	switch (type) {
		case 'location':
			return { label: t('metascrub', 'Location where the photo was taken'), icon: mdiMapMarkerOutline, weight: 100 }
		case 'serial':
			return { label: t('metascrub', 'Device serial number'), icon: mdiNumeric, weight: 90 }
		case 'hidden':
			return { label: t('metascrub', 'Hidden data after the image (motion photo, HDR map or extra preview)'), icon: mdiEyeOffOutline, weight: 85 }
		case 'author':
			return { label: t('metascrub', 'Author, owner or copyright'), icon: mdiAccountOutline, weight: 80 }
		case 'datetime':
			return { label: t('metascrub', 'Date and time'), icon: mdiCalendarClock, weight: 70 }
		case 'camera':
			return { label: t('metascrub', 'Camera and lens'), icon: mdiCameraOutline, weight: 60 }
		case 'thumbnail':
			return { label: t('metascrub', 'Embedded thumbnail (may show the photo before it was edited)'), icon: mdiImageMultipleOutline, weight: 55 }
		case 'comment':
			return { label: t('metascrub', 'Comments and descriptions'), icon: mdiCommentTextOutline, weight: 50 }
		case 'software':
			return { label: t('metascrub', 'Software used'), icon: mdiPencilOutline, weight: 40 }
		case 'makernote':
			return { label: t('metascrub', 'Manufacturer data'), icon: mdiFactory, weight: 35 }
		case 'xmp':
			return { label: t('metascrub', 'XMP metadata'), icon: mdiCodeTags, weight: 30 }
		case 'iptc':
			return { label: t('metascrub', 'IPTC metadata'), icon: mdiNewspaperVariantOutline, weight: 30 }
		case 'text':
			return { label: t('metascrub', 'Text fields'), icon: mdiFormatText, weight: 20 }
		default:
			return { label: t('metascrub', 'Other metadata'), icon: mdiTagOutline, weight: 0 }
	}
}

/**
 * OpenStreetMap link for a "lat, lon" detail string.
 *
 * @param detail Coordinates as returned by the API
 */
export function mapLink(detail: string): string | null {
	const match = detail.match(/^(-?\d+(?:\.\d+)?), (-?\d+(?:\.\d+)?)$/)
	if (!match) {
		return null
	}
	const [, lat, lon] = match
	return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=16/${lat}/${lon}`
}
