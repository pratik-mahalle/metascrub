<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Tests\Unit\Service;

use OCA\MetaScrub\Exception\CorruptFileException;
use OCA\MetaScrub\Exception\UnsupportedFormatException;
use OCA\MetaScrub\Service\Report;
use OCA\MetaScrub\Service\Scrubber;
use OCA\MetaScrub\Tests\Fixtures\ImageFactory;
use PHPUnit\Framework\TestCase;

class ScrubberTest extends TestCase {
	private Scrubber $scrubber;

	protected function setUp(): void {
		$this->scrubber = new Scrubber();
	}

	// ------------------------------------------------------------ JPEG

	public function testAnalyzeJpegReportsEverythingAPhoneLeaks(): void {
		$report = $this->scrubber->analyze(ImageFactory::phoneJpeg());

		$this->assertSame('jpeg', $report->format);
		$this->assertFalse($report->isClean());
		$this->assertSame(['12.97160, 77.59460'], $report->details(Report::LOCATION));
		$this->assertContains('Canon EOS R5', $report->details(Report::CAMERA));
		$this->assertContains(ImageFactory::SERIAL, $report->details(Report::SERIAL));
		$this->assertContains('2026:11:20 09:14:58', $report->details(Report::DATETIME));
		$this->assertContains('Darktable 4.8', $report->details(Report::SOFTWARE));
		$this->assertContains('Jane Doe', $report->details(Report::AUTHOR));
		foreach ([Report::THUMBNAIL, Report::MAKERNOTE, Report::XMP, Report::IPTC, Report::HIDDEN, Report::COMMENT] as $kind) {
			$this->assertTrue($report->has($kind), "expected finding: $kind");
		}
	}

	public function testAnalyzeDoesNotReportByteSavingsBeforeScrubbing(): void {
		$original = ImageFactory::phoneJpeg();
		$report = $this->scrubber->analyze($original);
		[$clean] = $this->scrubber->scrub($original);
		$this->assertSame(strlen($original) - strlen($clean), $report->getRemovedBytes());
	}

	public function testScrubJpegIsLosslessAndRemovesMetadata(): void {
		$original = ImageFactory::phoneJpeg();
		[$clean, $report] = $this->scrubber->scrub($original);

		$this->assertSamePixels($original, $clean);
		$this->assertLessThan(strlen($original), strlen($clean));
		$this->assertSame(strlen($original) - strlen($clean), $report->getRemovedBytes());

		$exif = $this->readExif($clean);
		$this->assertArrayNotHasKey('GPSLatitude', $exif);
		$this->assertArrayNotHasKey('Make', $exif);
		$this->assertArrayNotHasKey('Model', $exif);
		$this->assertArrayNotHasKey('DateTimeOriginal', $exif);

		$this->assertStringNotContainsString('Jane Doe', $clean);
		$this->assertStringNotContainsString(ImageFactory::SERIAL, $clean);
		$this->assertStringNotContainsString('motion-photo', $clean);
		$this->assertStringNotContainsString('CREATOR: gd-jpeg', $clean);
		$this->assertStringNotContainsString('Photoshop 3.0', $clean);
		$this->assertStringNotContainsString("MPF\0", $clean);
		$this->assertStringEndsWith("\xFF\xD9", $clean);
	}

	public function testScrubJpegKeepsOrientationColourProfileAndAdobeMarker(): void {
		[$clean, $report] = $this->scrubber->scrub(ImageFactory::phoneJpeg(orientation: 6));

		$this->assertTrue($report->jsonSerialize()['orientationKept']);
		$this->assertSame(6, $this->readExif($clean)['Orientation'] ?? null);
		$this->assertStringContainsString("ICC_PROFILE\0", $clean);
		$this->assertStringContainsString("Adobe\0", $clean);
		// JFIF must remain the first segment.
		$this->assertSame("\xFF\xD8\xFF\xE0", substr($clean, 0, 4));
	}

	public function testUprightPhotosGetNoExifAtAll(): void {
		[$clean, $report] = $this->scrubber->scrub(ImageFactory::phoneJpeg(orientation: 1));
		$this->assertFalse($report->jsonSerialize()['orientationKept']);
		$this->assertStringNotContainsString("Exif\0\0", $clean);
	}

	public function testLittleEndianExifIsUnderstood(): void {
		$report = $this->scrubber->analyze(ImageFactory::phoneJpeg(littleEndian: true));
		$this->assertSame(['12.97160, 77.59460'], $report->details(Report::LOCATION));
		$this->assertContains(ImageFactory::SERIAL, $report->details(Report::SERIAL));
	}

	public function testProgressiveJpegWithManyScansStaysIdentical(): void {
		$original = ImageFactory::phoneJpeg(progressive: true);
		$this->assertGreaterThan(1, substr_count($original, "\xFF\xDA"), 'fixture should be progressive');
		[$clean] = $this->scrubber->scrub($original);
		$this->assertSamePixels($original, $clean);
		$this->assertSame(substr_count($original, "\xFF\xDA"), substr_count($clean, "\xFF\xDA"));
	}

	public function testScrubbingIsIdempotent(): void {
		[$once] = $this->scrubber->scrub(ImageFactory::phoneJpeg());
		[$twice, $report] = $this->scrubber->scrub($once);
		$this->assertTrue($report->isClean());
		$this->assertSame($once, $twice);
		$this->assertSame(0, $report->getRemovedBytes());
	}

	// ------------------------------------------------------------- PNG

	public function testPngTextExifAndTrailingDataAreRemoved(): void {
		$original = ImageFactory::annotatedPng(orientation: 3);
		$report = $this->scrubber->analyze($original);
		$this->assertSame('png', $report->format);
		$this->assertContains('Jane Doe', $report->details(Report::AUTHOR));
		$this->assertContains('SuperEditor 9', $report->details(Report::SOFTWARE));
		$this->assertSame(['12.97160, 77.59460'], $report->details(Report::LOCATION));
		$this->assertContains('2026-11-22 18:30:00', $report->details(Report::DATETIME));
		$this->assertTrue($report->has(Report::XMP));
		$this->assertTrue($report->has(Report::COMMENT));
		$this->assertTrue($report->has(Report::HIDDEN));

		[$clean, $scrubReport] = $this->scrubber->scrub($original);
		$this->assertSamePixels($original, $clean);
		$this->assertTrue($scrubReport->jsonSerialize()['orientationKept']);
		$this->assertValidPngChunks($clean);

		$types = $this->pngChunkTypes($clean);
		foreach (['tEXt', 'zTXt', 'iTXt', 'tIME'] as $type) {
			$this->assertNotContains($type, $types);
		}
		// Only the orientation survives, before the image data.
		$this->assertSame(1, count(array_keys($types, 'eXIf')));
		$this->assertLessThan(array_search('IDAT', $types, true), array_search('eXIf', $types, true));
		$this->assertStringNotContainsString('TRAILING', $clean);
		$this->assertTrue($this->scrubber->analyze($clean)->isClean());
	}

	// ------------------------------------------------------------ WebP

	public function testWebpExifAndXmpChunksAreRemovedAndFlagsCleared(): void {
		$original = ImageFactory::annotatedWebp();
		$report = $this->scrubber->analyze($original);
		$this->assertSame('webp', $report->format);
		$this->assertSame(['12.97160, 77.59460'], $report->details(Report::LOCATION));
		$this->assertTrue($report->has(Report::XMP));

		[$clean] = $this->scrubber->scrub($original);
		$this->assertSamePixels($original, $clean);
		$this->assertStringNotContainsString('EXIF', $clean);
		$this->assertStringNotContainsString('XMP ', $clean);
		$this->assertSame(strlen($clean) - 8, unpack('V', $clean, 4)[1], 'RIFF size must match');
		$this->assertSame(0, ord($clean[20]) & 0x0C, 'EXIF/XMP flags must be cleared');
		$this->assertTrue($this->scrubber->analyze($clean)->isClean());
	}

	// ------------------------------------------------- clean & edge cases

	public function testCleanFilesAreReturnedUntouched(): void {
		foreach ([ImageFactory::plainPng(), ImageFactory::plainWebp()] as $plain) {
			[$out, $report] = $this->scrubber->scrub($plain);
			$this->assertTrue($report->isClean());
			$this->assertSame($plain, $out);
		}
	}

	public function testUnsupportedFormatsAreRejected(): void {
		$this->expectException(UnsupportedFormatException::class);
		$this->scrubber->scrub("GIF89a\x01\x00\x01\x00");
	}

	public function testTruncatedJpegIsRejectedRatherThanMangled(): void {
		$this->expectException(CorruptFileException::class);
		$this->scrubber->scrub(substr(ImageFactory::phoneJpeg(), 0, 400));
	}

	/**
	 * Every truncation and byte flip must either succeed or raise
	 * CorruptFileException: never a PHP warning, error or hang.
	 */
	public function testDamagedFilesNeverCrash(): void {
		mt_srand(42);
		foreach ([ImageFactory::phoneJpeg(), ImageFactory::annotatedPng(), ImageFactory::annotatedWebp()] as $image) {
			$len = strlen($image);
			for ($i = 0; $i < 300; $i++) {
				$broken = $image;
				if ($i % 2 === 0) {
					$broken = substr($broken, 0, mt_rand(4, $len - 1));
				} else {
					for ($k = 0; $k < 4; $k++) {
						$p = mt_rand(12, $len - 1);
						$broken[$p] = chr(mt_rand(0, 255));
					}
				}
				try {
					$this->scrubber->scrub($broken);
				} catch (CorruptFileException|UnsupportedFormatException) {
					// expected for damaged input
				}
			}
		}
		$this->assertTrue(true);
	}

	// --------------------------------------------------------- helpers

	private function assertSamePixels(string $a, string $b): void {
		$imgA = imagecreatefromstring($a);
		$imgB = imagecreatefromstring($b);
		$this->assertNotFalse($imgA, 'original must decode');
		$this->assertNotFalse($imgB, 'scrubbed image must decode');
		$this->assertSame(imagesx($imgA), imagesx($imgB));
		$this->assertSame(imagesy($imgA), imagesy($imgB));
		for ($x = 0; $x < imagesx($imgA); $x++) {
			for ($y = 0; $y < imagesy($imgA); $y++) {
				if (imagecolorat($imgA, $x, $y) !== imagecolorat($imgB, $x, $y)) {
					$this->fail("Pixel ($x, $y) differs");
				}
			}
		}
	}

	/** @return array<string, mixed> */
	private function readExif(string $jpeg): array {
		$exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpeg));
		return $exif === false ? [] : $exif;
	}

	/** @return list<string> */
	private function pngChunkTypes(string $png): array {
		$types = [];
		for ($pos = 8; $pos + 8 <= strlen($png);) {
			$len = unpack('N', $png, $pos)[1];
			$types[] = substr($png, $pos + 4, 4);
			$pos += 12 + $len;
		}
		return $types;
	}

	private function assertValidPngChunks(string $png): void {
		for ($pos = 8; $pos + 8 <= strlen($png);) {
			$len = unpack('N', $png, $pos)[1];
			$typeAndData = substr($png, $pos + 4, 4 + $len);
			$crc = unpack('N', $png, $pos + 8 + $len)[1];
			$this->assertSame(crc32($typeAndData), $crc, 'bad CRC in ' . substr($typeAndData, 0, 4));
			$pos += 12 + $len;
		}
		$this->assertSame('IEND', substr($png, -8, 4));
	}
}
