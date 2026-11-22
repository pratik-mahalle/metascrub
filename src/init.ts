/*!
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { registerFileAction } from '@nextcloud/files'
import { scrubAction } from './actions/scrubAction.ts'

registerFileAction(scrubAction)
