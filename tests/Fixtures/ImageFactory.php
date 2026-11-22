<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Tests\Fixtures;

/**
 * Builds real, decodable images with realistic metadata injected the way
 * cameras, phones and editors write it.
 */
class ImageFactory {
	public const LAT = 12.9716;   // Bengaluru
	public const LON = 77.5946;
	public const SERIAL = 'SN-0042-XYZ';
	public const TRAILER = 'ftypmp42 ... pretend this is a motion-photo video with its own GPS track';

	public static function gdImage(int $w = 64, int $h = 48): \GdImage {
		$img = imagecreatetruecolor($w, $h);
		for ($x = 0; $x < $w; $x++) {
			for ($y = 0; $y < $h; $y++) {
				imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 4) % 256, ($y * 5) % 256, (($x + $y) * 3) % 256));
			}
		}
		return $img;
	}

	public static function plainJpeg(bool $progressive = false): string {
		$img = self::gdImage();
		imageinterlace($img, $progressive);
		ob_start();
		imagejpeg($img, null, 90);
		return (string)ob_get_clean();
	}

	public static function plainPng(): string {
		ob_start();
		imagepng(self::gdImage());
		return (string)ob_get_clean();
	}

	public static function plainWebp(): string {
		ob_start();
		imagewebp(self::gdImage(), null, 80);
		return (string)ob_get_clean();
	}

	/**
	 * A phone-style JPEG: EXIF (camera, GPS, dates, serial, thumbnail IFD),
	 * XMP, IPTC, ICC profile, MPF and a motion-photo trailer.
	 */
	public static function phoneJpeg(bool $littleEndian = false, int $orientation = 6, bool $progressive = false): string {
		$jpeg = self::plainJpeg($progressive);
		$exif = "Exif\0\0" . self::exifTiff($littleEndian, $orientation);
		$segments = self::segment(0xE1, $exif)
			. self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><dc:creator>Jane Doe</dc:creator></x:xmpmeta>")
			. self::segment(0xE2, "ICC_PROFILE\0\x01\x01" . str_repeat("\x42", 64))
			. self::segment(0xE2, "MPF\0" . str_repeat("\0", 16))
			. self::segment(0xED, "Photoshop 3.0\08BIM\x04\x04" . str_repeat("\0", 12))
			. self::segment(0xEE, "Adobe\0\x64\0\0\0\0\x01");

		// Insert right after SOI + APP0 (JFIF).
		$app0Len = unpack('n', $jpeg, 4)[1];
		$cut = 4 + $app0Len;
		return substr($jpeg, 0, $cut) . $segments . substr($jpeg, $cut) . self::TRAILER;
	}

	public static function segment(int $marker, string $payload): string {
		return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
	}

	/**
	 * A PNG with text chunks, XMP, an eXIf chunk, tIME and trailing data.
	 */
	public static function annotatedPng(int $orientation = 3): string {
		$png = self::plainPng();
		$extra = self::pngChunk('tEXt', "Author\0Jane Doe")
			. self::pngChunk('tEXt', "Software\0SuperEditor 9")
			. self::pngChunk('zTXt', "Comment\0\0" . gzcompress('holiday at the secret beach'))
			. self::pngChunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0<x:xmpmeta/>")
			. self::pngChunk('eXIf', self::exifTiff(false, $orientation))
			. self::pngChunk('tIME', pack('nCCCCC', 2026, 11, 22, 18, 30, 0));
		// After IHDR (8 signature + 25 bytes IHDR chunk).
		return substr($png, 0, 33) . $extra . substr($png, 33) . 'TRAILING';
	}

	public static function pngChunk(string $type, string $data): string {
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * Convert GD's simple WebP into the extended format with EXIF + XMP.
	 */
	public static function annotatedWebp(): string {
		$simple = self::plainWebp();
		$image = substr($simple, 12); // the VP8/VP8L chunk
		$w = 64 - 1;
		$h = 48 - 1;
		$vp8x = 'VP8X' . pack('V', 10) . chr(0x08 | 0x04) . "\0\0\0"
			. substr(pack('V', $w), 0, 3) . substr(pack('V', $h), 0, 3);
		$exif = self::exifTiff(true, 1);
		$xmp = '<x:xmpmeta><dc:creator>Jane</dc:creator></x:xmpmeta>';
		$body = $vp8x . $image
			. 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 ? "\0" : '')
			. 'XMP ' . pack('V', strlen($xmp)) . $xmp . (strlen($xmp) % 2 ? "\0" : '');
		return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
	}

	/**
	 * EXIF TIFF block: IFD0 (+ ExifIFD, GPS IFD) and IFD1 thumbnail.
	 */
	public static function exifTiff(bool $le, int $orientation): string {
		$ifd0 = [
			[0x010F, 2, 'Canon'],
			[0x0110, 2, 'Canon EOS R5'],
			[0x0112, 3, $orientation],
			[0x0131, 2, 'Darktable 4.8'],
			[0x0132, 2, '2026:11:20 09:15:00'],
			[0x013B, 2, 'Jane Doe'],
			[0x8769, 4, 'EXIF'],
			[0x8825, 4, 'GPS'],
		];
		$exifIfd = [
			[0x9003, 2, '2026:11:20 09:14:58'],
			[0xA431, 2, self::SERIAL],
			[0x927C, 7, str_repeat("\0", 24)],
		];
		$gps = [
			[0x0001, 2, 'N'],
			[0x0002, 5, self::dms(self::LAT)],
			[0x0003, 2, 'E'],
			[0x0004, 5, self::dms(self::LON)],
		];
		$ifd1 = [
			[0x0201, 4, 0],
			[0x0202, 4, 0],
		];

		$sizes = array_map([self::class, 'ifdSize'], [$ifd0, $exifIfd, $gps, $ifd1]);
		$off0 = 8;
		$offExif = $off0 + $sizes[0];
		$offGps = $offExif + $sizes[1];
		$off1 = $offGps + $sizes[2];

		foreach ($ifd0 as &$entry) {
			$entry[2] = match ($entry[2]) {
				'EXIF' => $offExif,
				'GPS' => $offGps,
				default => $entry[2],
			};
		}
		unset($entry);

		$s = $le ? 'v' : 'n';
		$l = $le ? 'V' : 'N';
		return ($le ? 'II' : 'MM') . pack($s, 42) . pack($l, $off0)
			. self::ifd($ifd0, $off0, $off1, $le)
			. self::ifd($exifIfd, $offExif, 0, $le)
			. self::ifd($gps, $offGps, 0, $le)
			. self::ifd($ifd1, $off1, 0, $le);
	}

	/** @return list<array{int, int}> */
	private static function dms(float $deg): array {
		$d = (int)$deg;
		$m = (int)(($deg - $d) * 60);
		$sec = (int)round((($deg - $d) * 60 - $m) * 60 * 1000);
		return [[$d, 1], [$m, 1], [$sec, 1000]];
	}

	private static function valueBytes(array $entry, bool $le): string {
		[, $type, $value] = $entry;
		$s = $le ? 'v' : 'n';
		$l = $le ? 'V' : 'N';
		return match ($type) {
			2 => $value . "\0",
			3 => pack($s, $value),
			4 => pack($l, $value),
			5 => implode('', array_map(fn ($r) => pack($l, $r[0]) . pack($l, $r[1]), $value)),
			7 => $value,
		};
	}

	private static function count(array $entry): int {
		return match ($entry[1]) {
			2 => strlen($entry[2]) + 1,
			5 => count($entry[2]),
			7 => strlen($entry[2]),
			default => 1,
		};
	}

	private static function ifdSize(array $entries): int {
		$size = 2 + 12 * count($entries) + 4;
		foreach ($entries as $e) {
			$len = strlen(self::valueBytes($e, false));
			if ($len > 4) {
				$size += $len + ($len % 2);
			}
		}
		return $size;
	}

	private static function ifd(array $entries, int $offset, int $next, bool $le): string {
		$s = $le ? 'v' : 'n';
		$l = $le ? 'V' : 'N';
		$dataOffset = $offset + 2 + 12 * count($entries) + 4;
		$table = pack($s, count($entries));
		$data = '';
		foreach ($entries as $e) {
			$bytes = self::valueBytes($e, $le);
			$table .= pack($s, $e[0]) . pack($s, $e[1]) . pack($l, self::count($e));
			if (strlen($bytes) <= 4) {
				$table .= str_pad($bytes, 4, "\0");
			} else {
				$table .= pack($l, $dataOffset + strlen($data));
				$data .= $bytes . (strlen($bytes) % 2 ? "\0" : '');
			}
		}
		return $table . pack($l, $next) . $data;
	}
}
