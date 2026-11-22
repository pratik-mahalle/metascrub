<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Service;

/**
 * Turns an EXIF (TIFF) block into human-meaningful findings.
 */
class ExifInspector {
	private const IFD0_MAKE = 0x010F;
	private const IFD0_MODEL = 0x0110;
	private const IFD0_ORIENTATION = 0x0112;
	private const IFD0_SOFTWARE = 0x0131;
	private const IFD0_DATETIME = 0x0132;
	private const IFD0_ARTIST = 0x013B;
	private const IFD0_COPYRIGHT = 0x8298;
	private const IFD0_EXIF_POINTER = 0x8769;
	private const IFD0_GPS_POINTER = 0x8825;

	private const EXIF_DATETIME_ORIGINAL = 0x9003;
	private const EXIF_DATETIME_DIGITIZED = 0x9004;
	private const EXIF_MAKERNOTE = 0x927C;
	private const EXIF_USER_COMMENT = 0x9286;
	private const EXIF_OWNER = 0xA430;
	private const EXIF_BODY_SERIAL = 0xA431;
	private const EXIF_LENS_MODEL = 0xA434;
	private const EXIF_LENS_SERIAL = 0xA435;

	/**
	 * Inspect an EXIF block and record findings.
	 *
	 * @return int|null The image orientation (1-8) if present
	 */
	public static function inspect(string $tiff, Report $report): ?int {
		$reader = TiffReader::open($tiff);
		if ($reader === null) {
			$report->add(Report::OTHER);
			return null;
		}

		$before = $report->kindCount();
		$ifd0 = $reader->readIfd($reader->firstIfdOffset());
		$e = $ifd0['entries'];

		$camera = trim(implode(' ', array_filter([
			isset($e[self::IFD0_MAKE]) ? $reader->string($e[self::IFD0_MAKE]) : null,
			isset($e[self::IFD0_MODEL]) ? $reader->string($e[self::IFD0_MODEL]) : null,
		])));
		if ($camera !== '') {
			$report->add(Report::CAMERA, self::dedupeMake($camera));
		}
		if (isset($e[self::IFD0_SOFTWARE])) {
			$report->add(Report::SOFTWARE, $reader->string($e[self::IFD0_SOFTWARE]));
		}
		if (isset($e[self::IFD0_DATETIME])) {
			$report->add(Report::DATETIME, $reader->string($e[self::IFD0_DATETIME]));
		}
		foreach ([self::IFD0_ARTIST, self::IFD0_COPYRIGHT] as $tag) {
			if (isset($e[$tag])) {
				$report->add(Report::AUTHOR, $reader->string($e[$tag]));
			}
		}

		if (isset($e[self::IFD0_EXIF_POINTER])) {
			$offset = $reader->int($e[self::IFD0_EXIF_POINTER]);
			if ($offset !== null) {
				self::inspectExifIfd($reader, $offset, $report);
			}
		}

		if (isset($e[self::IFD0_GPS_POINTER])) {
			$offset = $reader->int($e[self::IFD0_GPS_POINTER]);
			$gps = $offset !== null ? $reader->readIfd($offset) : ['entries' => []];
			if ($gps['entries'] !== []) {
				$report->add(Report::LOCATION, self::coordinates($reader, $gps['entries']));
			}
		}

		// IFD1 holds the embedded thumbnail: a second, smaller copy of the photo.
		if ($ifd0['next'] > 0) {
			$ifd1 = $reader->readIfd($ifd0['next']);
			if ($ifd1['entries'] !== []) {
				$report->add(Report::THUMBNAIL);
			}
		}

		// Orientation alone is not a finding: we keep it so photos don't turn sideways.
		$known = [self::IFD0_ORIENTATION, 0x011A, 0x011B, 0x0128, 0x0213];
		if ($report->kindCount() === $before && array_diff(array_keys($e), $known) !== []) {
			$report->add(Report::OTHER);
		}

		if (isset($e[self::IFD0_ORIENTATION])) {
			$orientation = $reader->int($e[self::IFD0_ORIENTATION]);
			if ($orientation !== null && $orientation >= 1 && $orientation <= 8) {
				return $orientation;
			}
		}
		return null;
	}

	private static function inspectExifIfd(TiffReader $reader, int $offset, Report $report): void {
		$e = $reader->readIfd($offset)['entries'];
		foreach ([self::EXIF_DATETIME_ORIGINAL, self::EXIF_DATETIME_DIGITIZED] as $tag) {
			if (isset($e[$tag])) {
				$report->add(Report::DATETIME, $reader->string($e[$tag]));
			}
		}
		foreach ([self::EXIF_BODY_SERIAL, self::EXIF_LENS_SERIAL] as $tag) {
			if (isset($e[$tag])) {
				$report->add(Report::SERIAL, $reader->string($e[$tag]));
			}
		}
		if (isset($e[self::EXIF_OWNER])) {
			$report->add(Report::AUTHOR, $reader->string($e[self::EXIF_OWNER]));
		}
		if (isset($e[self::EXIF_LENS_MODEL])) {
			$report->add(Report::CAMERA, $reader->string($e[self::EXIF_LENS_MODEL]));
		}
		if (isset($e[self::EXIF_MAKERNOTE])) {
			$report->add(Report::MAKERNOTE);
		}
		if (isset($e[self::EXIF_USER_COMMENT])) {
			$report->add(Report::COMMENT);
		}
	}

	/**
	 * @param array<int, array{type: int, count: int, pos: int}> $gps
	 */
	private static function coordinates(TiffReader $reader, array $gps): ?string {
		if (!isset($gps[1], $gps[2], $gps[3], $gps[4])) {
			return null;
		}
		$lat = self::degrees($reader->rationals($gps[2]));
		$lon = self::degrees($reader->rationals($gps[4]));
		if ($lat === null || $lon === null || ($lat === 0.0 && $lon === 0.0)) {
			return null;
		}
		if (strtoupper((string)$reader->string($gps[1])) === 'S') {
			$lat = -$lat;
		}
		if (strtoupper((string)$reader->string($gps[3])) === 'W') {
			$lon = -$lon;
		}
		return sprintf('%.5f, %.5f', $lat, $lon);
	}

	/** @param list<float>|null $dms */
	private static function degrees(?array $dms): ?float {
		if ($dms === null || count($dms) !== 3) {
			return null;
		}
		return $dms[0] + $dms[1] / 60 + $dms[2] / 3600;
	}

	/** "Canon Canon EOS R5" -> "Canon EOS R5" */
	private static function dedupeMake(string $camera): string {
		$parts = explode(' ', $camera, 2);
		if (count($parts) === 2 && stripos($parts[1], $parts[0]) === 0) {
			return $parts[1];
		}
		return $camera;
	}
}
