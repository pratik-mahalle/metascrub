<!--
  - SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

## 1.0.0

### Added
- "Remove metadata" file action for JPEG, PNG and WebP, for single files and selections
- Inspection dialog listing location (with map link), camera, serial numbers, dates, author, software, thumbnails, XMP/IPTC and hidden trailing data
- Lossless removal that keeps ICC profiles, the Adobe marker and orientation
- OCS API: `GET /api/v1/files/{id}` and `POST /api/v1/files/{id}/scrub`
- `max_file_size_mb` app setting
