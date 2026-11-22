<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub;

/**
 * Types used by the OpenAPI extractor.
 *
 * @psalm-type MetaScrubFinding = array{
 *     type: 'location'|'camera'|'serial'|'datetime'|'software'|'author'|'thumbnail'|'makernote'|'xmp'|'iptc'|'comment'|'text'|'hidden'|'other',
 *     details: list<string>,
 * }
 *
 * @psalm-type MetaScrubReport = array{
 *     format: 'jpeg'|'png'|'webp',
 *     clean: bool,
 *     findings: list<MetaScrubFinding>,
 *     removedBytes: int,
 *     orientationKept: bool,
 * }
 *
 * @psalm-type MetaScrubResult = MetaScrubReport&array{
 *     changed: bool,
 *     size: int,
 *     mtime: int,
 *     etag: string,
 * }
 */
class ResponseDefinitions {
}
