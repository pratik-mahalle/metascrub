<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Service;

/**
 * Minimal, defensive reader for the TIFF structure used by EXIF blocks.
 *
 * It only reads what we need to tell the user *what* a file reveals
 * (camera, GPS position, dates, serial numbers...). Every offset is bounds
 * checked so a malformed or hostile file can never make us read outside
 * the buffer.
 */
class TiffReader {
	private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];

	private bool $le;

	private function __construct(
		private string $data,
	) {
		$this->le = substr($data, 0, 2) === 'II';
	}

	/**
	 * @param string $data Raw TIFF bytes (starting with "II*\0" or "MM\0*")
	 */
	public static function open(string $data): ?self {
		if (strlen($data) < 8) {
			return null;
		}
		$order = substr($data, 0, 2);
		if ($order !== 'II' && $order !== 'MM') {
			return null;
		}
		$reader = new self($data);
		return $reader->u16(2) === 42 ? $reader : null;
	}

	public function firstIfdOffset(): int {
		return $this->u32(4) ?? 0;
	}

	/**
	 * Read one IFD.
	 *
	 * @return array{entries: array<int, array{type: int, count: int, pos: int}>, next: int}
	 */
	public function readIfd(int $offset): array {
		$result = ['entries' => [], 'next' => 0];
		$count = $this->u16($offset);
		if ($count === null || $count > 1000) {
			return $result;
		}
		for ($i = 0; $i < $count; $i++) {
			$pos = $offset + 2 + $i * 12;
			$tag = $this->u16($pos);
			$type = $this->u16($pos + 2);
			$n = $this->u32($pos + 4);
			if ($tag === null || $type === null || $n === null) {
				break;
			}
			$result['entries'][$tag] = ['type' => $type, 'count' => $n, 'pos' => $pos + 8];
		}
		$result['next'] = $this->u32($offset + 2 + $count * 12) ?? 0;
		return $result;
	}

	/**
	 * Position of the value data for an entry (inline when it fits in 4 bytes).
	 *
	 * @param array{type: int, count: int, pos: int} $entry
	 */
	private function valuePos(array $entry): ?int {
		$size = (self::TYPE_SIZES[$entry['type']] ?? 0) * $entry['count'];
		if ($size === 0) {
			return null;
		}
		$pos = $size <= 4 ? $entry['pos'] : $this->u32($entry['pos']);
		if ($pos === null || $pos + $size > strlen($this->data)) {
			return null;
		}
		return $pos;
	}

	/** @param array{type: int, count: int, pos: int} $entry */
	public function int(array $entry): ?int {
		$pos = $this->valuePos($entry);
		if ($pos === null) {
			return null;
		}
		return match ($entry['type']) {
			1, 7 => ord($this->data[$pos]),
			3 => $this->u16($pos),
			4 => $this->u32($pos),
			default => null,
		};
	}

	/** @param array{type: int, count: int, pos: int} $entry */
	public function string(array $entry): ?string {
		if ($entry['type'] !== 2 && $entry['type'] !== 7) {
			return null;
		}
		$pos = $this->valuePos($entry);
		if ($pos === null) {
			return null;
		}
		$raw = substr($this->data, $pos, min($entry['count'], 256));
		$value = trim(str_replace("\0", ' ', $raw));
		// Only report printable text, never binary junk.
		return $value !== '' && mb_check_encoding($value, 'UTF-8') && !preg_match('/[\x00-\x08\x0E-\x1F]/', $value)
			? $value
			: null;
	}

	/**
	 * @param array{type: int, count: int, pos: int} $entry
	 * @return list<float>|null
	 */
	public function rationals(array $entry): ?array {
		if ($entry['type'] !== 5 || $entry['count'] > 16) {
			return null;
		}
		$pos = $this->valuePos($entry);
		if ($pos === null) {
			return null;
		}
		$values = [];
		for ($i = 0; $i < $entry['count']; $i++) {
			$num = $this->u32($pos + $i * 8);
			$den = $this->u32($pos + $i * 8 + 4);
			if ($num === null || $den === null) {
				return null;
			}
			$values[] = $den === 0 ? 0.0 : $num / $den;
		}
		return $values;
	}

	private function u16(int $pos): ?int {
		if ($pos < 0 || $pos + 2 > strlen($this->data)) {
			return null;
		}
		return unpack($this->le ? 'v' : 'n', $this->data, $pos)[1];
	}

	private function u32(int $pos): ?int {
		if ($pos < 0 || $pos + 4 > strlen($this->data)) {
			return null;
		}
		return unpack($this->le ? 'V' : 'N', $this->data, $pos)[1];
	}
}
