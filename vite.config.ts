/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	init: 'src/init.ts',
}, {
	// Styles of the lazily loaded dialog are injected with its chunk.
	inlineCSS: true,
})
