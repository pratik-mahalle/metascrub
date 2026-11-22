<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Service;

use OCA\MetaScrub\AppInfo\Application;
use OCA\MetaScrub\Exception\FileTooLargeException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Glue between the pure Scrubber and Nextcloud's virtual filesystem.
 */
class ScrubService {
	public const DEFAULT_MAX_SIZE_MB = 64;

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
		private Scrubber $scrubber,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function analyze(string $userId, int $fileId): Report {
		$file = $this->getFile($userId, $fileId);
		return $this->scrubber->analyze($this->read($file));
	}

	/**
	 * Remove metadata from a file in place. The previous content is kept by
	 * the Versions app, so the user can always restore the original.
	 *
	 * @return array{report: Report, changed: bool, size: int, mtime: int, etag: string}
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function scrub(string $userId, int $fileId): array {
		$file = $this->getFile($userId, $fileId);
		if (!$file->isUpdateable()) {
			throw new NotPermittedException('You are not allowed to modify this file');
		}

		[$clean, $report] = $this->scrubber->scrub($this->read($file));
		$changed = !$report->isClean();
		if ($changed) {
			$file->putContent($clean);
			$this->logger->info('Removed {bytes} bytes of metadata from file {fileId}', [
				'app' => Application::APP_ID,
				'bytes' => $report->getRemovedBytes(),
				'fileId' => $fileId,
			]);
		}

		return [
			'report' => $report,
			'changed' => $changed,
			'size' => (int)$file->getSize(),
			'mtime' => $file->getMTime(),
			'etag' => $file->getEtag(),
		];
	}

	public function getMaxSize(): int {
		$mb = $this->appConfig->getValueInt(Application::APP_ID, 'max_file_size_mb', self::DEFAULT_MAX_SIZE_MB);
		return max(1, $mb) * 1024 * 1024;
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function getFile(string $userId, int $fileId): File {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			throw new NotFoundException('File not found');
		}
		if (!$node->isReadable()) {
			throw new NotPermittedException('You are not allowed to read this file');
		}
		return $node;
	}

	private function read(File $file): string {
		if ($file->getSize() > $this->getMaxSize()) {
			throw new FileTooLargeException('File is larger than the configured limit');
		}
		return $file->getContent();
	}
}
