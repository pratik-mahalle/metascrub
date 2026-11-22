<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Service;

use OCA\MetaScrub\Exception\CorruptFileException;
use OCA\MetaScrub\Exception\UnsupportedFormatException;

/**
 * Lossless metadata removal for JPEG, PNG and WebP.
 *
 * The image data itself is copied byte for byte: we only drop the
 * containers' metadata blocks, so there is no re-encoding and no quality
 * loss. Things that change how the image *looks* are kept on purpose:
 * ICC colour profiles, the Adobe colour-transform marker and the
 * orientation flag (rewritten as a tiny, orientation-only EXIF block).
 *
 * This class has no Nextcloud dependencies so it can be unit tested alone.
 */
class Scrubber {
	public const FORMAT_JPEG = 'jpeg';
	public const FORMAT_PNG = 'png';
	public const FORMAT_WEBP = 'webp';

	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	public static function detectFormat(string $data): ?string {
		if (str_starts_with($data, "\xFF\xD8\xFF")) {
			return self::FORMAT_JPEG;
		}
		if (str_starts_with($data, self::PNG_SIGNATURE)) {
			return self::FORMAT_PNG;
		}
		if (strlen($data) >= 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
			return self::FORMAT_WEBP;
		}
		return null;
	}

	/**
	 * Report what the file reveals, without changing anything.
	 */
	public function analyze(string $data): Report {
		return $this->process($data)[1];
	}

	/**
	 * @return array{0: string, 1: Report} The cleaned bytes and the report
	 * @throws UnsupportedFormatException
	 * @throws CorruptFileException
	 */
	public function scrub(string $data): array {
		return $this->process($data);
	}

	/**
	 * @return array{0: string, 1: Report}
	 */
	private function process(string $data): array {
		[$clean, $report] = match (self::detectFormat($data)) {
			self::FORMAT_JPEG => $this->jpeg($data),
			self::FORMAT_PNG => $this->png($data),
			self::FORMAT_WEBP => $this->webp($data),
			default => throw new UnsupportedFormatException('Only JPEG, PNG and WebP images are supported'),
		};
		// If nothing was found the original bytes are returned untouched.
		if ($report->isClean()) {
			$report->setRemovedBytes(0);
			return [$data, $report];
		}
		$report->setRemovedBytes(strlen($data) - strlen($clean));
		return [$clean, $report];
	}

	// ---------------------------------------------------------------- JPEG

	/** @return array{0: string, 1: Report} */
	private function jpeg(string $data): array {
		$report = new Report(self::FORMAT_JPEG);
		$len = strlen($data);
		$head = "\xFF\xD8";
		$body = '';
		$orientation = null;
		$pos = 2;
		$sawEoi = false;

		while ($pos < $len) {
			if ($data[$pos] !== "\xFF") {
				throw new CorruptFileException('Invalid JPEG marker');
			}
			// Skip optional fill bytes.
			while ($pos + 1 < $len && $data[$pos + 1] === "\xFF") {
				$pos++;
			}
			if ($pos + 1 >= $len) {
				throw new CorruptFileException('Truncated JPEG');
			}
			$marker = ord($data[$pos + 1]);

			if ($marker === 0xD9) { // EOI
				$body .= "\xFF\xD9";
				$pos += 2;
				$sawEoi = true;
				break;
			}
			if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
				$body .= substr($data, $pos, 2);
				$pos += 2;
				continue;
			}
			if ($pos + 4 > $len) {
				throw new CorruptFileException('Truncated JPEG segment');
			}
			$segLen = unpack('n', $data, $pos + 2)[1];
			if ($segLen < 2 || $pos + 2 + $segLen > $len) {
				throw new CorruptFileException('Invalid JPEG segment length');
			}
			$segment = substr($data, $pos, 2 + $segLen);
			$payload = substr($segment, 4);

			if ($marker === 0xDA) { // SOS: copy header plus entropy-coded data up to the next real marker
				$scanEnd = $this->findScanEnd($data, $pos + 2 + $segLen);
				$body .= substr($data, $pos, $scanEnd - $pos);
				$pos = $scanEnd;
				continue;
			}

			if ($this->keepJpegSegment($marker, $payload, $report, $orientation)) {
				// JFIF must stay first after SOI; everything else keeps its order.
				if ($marker === 0xE0 && $body === '' && str_starts_with($payload, "JFIF\0")) {
					$head .= $segment;
				} else {
					$body .= $segment;
				}
			}
			$pos += 2 + $segLen;
		}

		if (!$sawEoi) {
			throw new CorruptFileException('JPEG has no end-of-image marker');
		}
		if ($pos < $len) {
			// Motion photos, HDR gain maps, extra previews... appended after the image.
			$report->add(Report::HIDDEN);
		}

		if (!$report->isClean() && $orientation !== null && $orientation !== 1) {
			$exif = "Exif\0\0" . self::orientationOnlyTiff($orientation);
			$head .= "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
			$report->setOrientationKept(true);
		}

		return [$head . $body, $report];
	}

	/**
	 * Decide whether a JPEG segment survives, recording what it contained.
	 */
	private function keepJpegSegment(int $marker, string $payload, Report $report, ?int &$orientation): bool {
		switch (true) {
			case $marker === 0xE0: // APP0
				if (str_starts_with($payload, "JFIF\0")) {
					return true;
				}
				// JFXX carries a thumbnail; anything else is unknown metadata.
				$report->add(str_starts_with($payload, "JFXX\0") ? Report::THUMBNAIL : Report::OTHER);
				return false;

			case $marker === 0xE1: // APP1: EXIF or XMP
				if (str_starts_with($payload, "Exif\0")) {
					$orientation = ExifInspector::inspect(substr($payload, 6), $report) ?? $orientation;
					// An EXIF block holding nothing but orientation is already clean.
					return false;
				}
				if (str_starts_with($payload, 'http://ns.adobe.com/xap/1.0/') || str_starts_with($payload, 'http://ns.adobe.com/xmp/extension/')) {
					$report->add(Report::XMP);
					return false;
				}
				$report->add(Report::OTHER);
				return false;

			case $marker === 0xE2: // APP2: ICC profile (keep), MPF / FlashPix (drop)
				if (str_starts_with($payload, "ICC_PROFILE\0")) {
					return true;
				}
				$report->add(str_starts_with($payload, "MPF\0") ? Report::HIDDEN : Report::OTHER);
				return false;

			case $marker === 0xED: // APP13: Photoshop / IPTC
				$report->add(Report::IPTC);
				return false;

			case $marker === 0xEE: // APP14: Adobe colour transform, affects decoding
				if (str_starts_with($payload, 'Adobe')) {
					return true;
				}
				$report->add(Report::OTHER);
				return false;

			case $marker >= 0xE3 && $marker <= 0xEF: // other APPn: vendor metadata
				$report->add(Report::OTHER);
				return false;

			case $marker === 0xFE: // COM
				$text = trim(preg_replace('/[^\P{C}\n]+/u', '', (string)mb_convert_encoding(substr($payload, 0, 120), 'UTF-8', 'UTF-8')) ?? '');
				$report->add(Report::COMMENT, $text !== '' ? $text : null);
				return false;

			default: // DQT, DHT, SOFn, DRI, DNL, ... needed to decode
				return true;
		}
	}

	/**
	 * Find where entropy-coded data after SOS ends: the first 0xFF that is
	 * not byte stuffing (FF00) and not a restart marker (FFD0-FFD7).
	 */
	private function findScanEnd(string $data, int $pos): int {
		$len = strlen($data);
		while (($pos = strpos($data, "\xFF", $pos)) !== false) {
			if ($pos + 1 >= $len) {
				throw new CorruptFileException('Truncated JPEG scan');
			}
			$next = ord($data[$pos + 1]);
			if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7) || $next === 0xFF) {
				$pos += ($next === 0xFF) ? 1 : 2;
				continue;
			}
			return $pos;
		}
		throw new CorruptFileException('JPEG scan has no end');
	}

	// ----------------------------------------------------------------- PNG

	/** @return array{0: string, 1: Report} */
	private function png(string $data): array {
		$report = new Report(self::FORMAT_PNG);
		$len = strlen($data);
		$out = self::PNG_SIGNATURE;
		$pos = 8;
		$orientation = null;
		$orientationWritten = false;
		$sawEnd = false;
		$chunks = [];

		// First pass: classify. Second pass: assemble (orientation must precede IDAT).
		while ($pos + 12 <= $len) {
			$chunkLen = unpack('N', $data, $pos)[1];
			$type = substr($data, $pos + 4, 4);
			if ($chunkLen > $len || $pos + 12 + $chunkLen > $len || !preg_match('/^[A-Za-z]{4}$/', $type)) {
				throw new CorruptFileException('Invalid PNG chunk');
			}
			$chunkData = substr($data, $pos + 8, $chunkLen);
			$raw = substr($data, $pos, 12 + $chunkLen);
			$pos += 12 + $chunkLen;

			switch ($type) {
				case 'tEXt':
				case 'zTXt':
				case 'iTXt':
					$this->pngText($type, $chunkData, $report);
					break;
				case 'eXIf':
					$orientation = ExifInspector::inspect($chunkData, $report) ?? $orientation;
					break;
				case 'tIME':
					$report->add(Report::DATETIME, self::pngTime($chunkData));
					break;
				case 'dSIG':
					$report->add(Report::OTHER);
					break;
				default:
					$chunks[] = [$type, $raw];
			}
			if ($type === 'IEND') {
				$sawEnd = true;
				break;
			}
		}
		if (!$sawEnd) {
			throw new CorruptFileException('PNG has no IEND chunk');
		}
		if ($pos < $len) {
			$report->add(Report::HIDDEN);
		}

		$keepOrientation = !$report->isClean() && $orientation !== null && $orientation !== 1;
		foreach ($chunks as [$type, $raw]) {
			if ($keepOrientation && !$orientationWritten && ($type === 'IDAT' || $type === 'IEND')) {
				$out .= self::pngChunk('eXIf', self::orientationOnlyTiff($orientation));
				$orientationWritten = true;
				$report->setOrientationKept(true);
			}
			$out .= $raw;
		}
		return [$out, $report];
	}

	private function pngText(string $type, string $chunkData, Report $report): void {
		$nul = strpos($chunkData, "\0");
		$keyword = $nul === false ? '' : substr($chunkData, 0, $nul);
		$lower = strtolower($keyword);

		if ($lower === 'xml:com.adobe.xmp') {
			$report->add(Report::XMP);
			return;
		}
		if (str_starts_with($lower, 'raw profile type exif') || str_starts_with($lower, 'raw profile type app1')) {
			$report->add(Report::OTHER, $keyword);
			return;
		}
		if (str_starts_with($lower, 'raw profile type iptc')) {
			$report->add(Report::IPTC);
			return;
		}

		$value = null;
		if ($type === 'tEXt' && $nul !== false) {
			$value = substr($chunkData, $nul + 1, 80);
		}
		if ($type === 'iTXt' && $nul !== false && ($chunkData[$nul + 1] ?? "\1") === "\0") {
			// keyword\0 compression-flag compression-method lang\0 translated\0 text
			$parts = explode("\0", substr($chunkData, $nul + 3), 3);
			$value = isset($parts[2]) ? substr($parts[2], 0, 80) : null;
		}
		if ($value !== null) {
			$value = mb_convert_encoding($value, 'UTF-8', $type === 'tEXt' ? 'ISO-8859-1' : 'UTF-8');
		}

		$kind = match ($lower) {
			'author', 'copyright' => Report::AUTHOR,
			'software' => Report::SOFTWARE,
			'creation time' => Report::DATETIME,
			'comment', 'description', 'title' => Report::COMMENT,
			'source' => Report::CAMERA,
			default => Report::TEXT,
		};
		$detail = $kind === Report::TEXT ? $keyword : $value;
		$report->add($kind, $detail !== null ? trim($detail) : null);
	}

	private static function pngTime(string $chunk): ?string {
		if (strlen($chunk) !== 7) {
			return null;
		}
		$t = unpack('nyear/Cmonth/Cday/Chour/Cminute/Csecond', $chunk);
		return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $t['year'], $t['month'], $t['day'], $t['hour'], $t['minute'], $t['second']);
	}

	private static function pngChunk(string $type, string $data): string {
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	// ---------------------------------------------------------------- WebP

	/** @return array{0: string, 1: Report} */
	private function webp(string $data): array {
		$report = new Report(self::FORMAT_WEBP);
		$riffSize = unpack('V', $data, 4)[1];
		$end = 8 + $riffSize;
		if ($riffSize < 4 || $end > strlen($data)) {
			throw new CorruptFileException('Invalid WebP size');
		}
		$keep = ['VP8X', 'VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];
		$out = '';
		$vp8xOffset = null;
		$pos = 12;

		while ($pos + 8 <= $end) {
			$fourcc = substr($data, $pos, 4);
			$size = unpack('V', $data, $pos + 4)[1];
			$padded = $size + ($size & 1);
			if ($pos + 8 + $size > $end) {
				throw new CorruptFileException('Invalid WebP chunk');
			}
			$chunk = substr($data, $pos, 8 + min($padded, $end - $pos - 8));

			if (in_array($fourcc, $keep, true)) {
				if ($fourcc === 'VP8X') {
					$vp8xOffset = strlen($out);
				}
				$out .= $chunk;
			} elseif ($fourcc === 'EXIF') {
				$exif = substr($data, $pos + 8, $size);
				if (str_starts_with($exif, "Exif\0\0")) {
					$exif = substr($exif, 6);
				}
				ExifInspector::inspect($exif, $report);
			} elseif ($fourcc === 'XMP ') {
				$report->add(Report::XMP);
			} else {
				$report->add(Report::OTHER);
			}
			$pos += 8 + $padded;
		}
		if ($end < strlen($data)) {
			$report->add(Report::HIDDEN);
		}

		// Clear the EXIF (0x08) and XMP (0x04) flags in the VP8X header.
		if ($vp8xOffset !== null) {
			$flags = ord($out[$vp8xOffset + 8]) & ~0x0C;
			$out[$vp8xOffset + 8] = chr($flags);
		}

		return ['RIFF' . pack('V', strlen($out) + 4) . 'WEBP' . $out, $report];
	}

	// -------------------------------------------------------------- shared

	/**
	 * A minimal big-endian TIFF block containing only the Orientation tag.
	 */
	public static function orientationOnlyTiff(int $orientation): string {
		return "MM\0\x2A" . pack('N', 8)        // header, IFD0 at offset 8
			. pack('n', 1)                       // one entry
			. pack('nnN', 0x0112, 3, 1)          // Orientation, SHORT, count 1
			. pack('n', $orientation) . "\0\0"   // value, padded to 4 bytes
			. pack('N', 0);                      // no next IFD
	}
}
