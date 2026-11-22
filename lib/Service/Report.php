<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Service;

use JsonSerializable;

/**
 * What a file reveals, grouped by kind.
 *
 * Kinds are stable identifiers; the frontend turns them into translated
 * labels so the backend stays language-neutral.
 */
class Report implements JsonSerializable {
	public const LOCATION = 'location';
	public const CAMERA = 'camera';
	public const SERIAL = 'serial';
	public const DATETIME = 'datetime';
	public const SOFTWARE = 'software';
	public const AUTHOR = 'author';
	public const THUMBNAIL = 'thumbnail';
	public const MAKERNOTE = 'makernote';
	public const XMP = 'xmp';
	public const IPTC = 'iptc';
	public const COMMENT = 'comment';
	public const TEXT = 'text';
	public const HIDDEN = 'hidden';
	public const OTHER = 'other';

	/** @var array<string, list<string>> */
	private array $findings = [];
	private int $removedBytes = 0;
	private bool $orientationKept = false;

	public function __construct(
		public readonly string $format,
	) {
	}

	public function add(string $kind, ?string $detail = null): void {
		$this->findings[$kind] ??= [];
		if ($detail !== null && $detail !== '' && !in_array($detail, $this->findings[$kind], true)
			&& count($this->findings[$kind]) < 5) {
			$this->findings[$kind][] = $detail;
		}
	}

	public function addRemovedBytes(int $bytes): void {
		$this->removedBytes += $bytes;
	}

	public function setRemovedBytes(int $bytes): void {
		$this->removedBytes = max(0, $bytes);
	}

	public function setOrientationKept(bool $kept): void {
		$this->orientationKept = $kept;
	}

	public function has(string $kind): bool {
		return isset($this->findings[$kind]);
	}

	/** @return list<string> */
	public function details(string $kind): array {
		return $this->findings[$kind] ?? [];
	}

	public function kindCount(): int {
		return count($this->findings);
	}

	public function isClean(): bool {
		return $this->findings === [];
	}

	public function getRemovedBytes(): int {
		return $this->removedBytes;
	}

	/**
	 * @return array{format: string, clean: bool, findings: list<array{type: string, details: list<string>}>, removedBytes: int, orientationKept: bool}
	 */
	public function jsonSerialize(): array {
		$findings = [];
		foreach ($this->findings as $type => $details) {
			$findings[] = ['type' => $type, 'details' => $details];
		}
		return [
			'format' => $this->format,
			'clean' => $this->isClean(),
			'findings' => $findings,
			'removedBytes' => $this->removedBytes,
			'orientationKept' => $this->orientationKept,
		];
	}
}
