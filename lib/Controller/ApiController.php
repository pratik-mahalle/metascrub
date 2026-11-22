<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Controller;

use OCA\MetaScrub\Exception\CorruptFileException;
use OCA\MetaScrub\Exception\FileTooLargeException;
use OCA\MetaScrub\Exception\UnsupportedFormatException;
use OCA\MetaScrub\ResponseDefinitions;
use OCA\MetaScrub\Service\ScrubService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type MetaScrubReport from ResponseDefinitions
 * @psalm-import-type MetaScrubResult from ResponseDefinitions
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ScrubService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Show which metadata a file contains, without changing it
	 *
	 * @param int $fileId ID of the file
	 * @return DataResponse<Http::STATUS_OK, MetaScrubReport, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{message: string}, array{}>
	 *
	 * 200: Metadata report returned
	 * 400: File is not a supported image or is damaged
	 * 403: Not allowed to read the file
	 * 404: File not found
	 * 413: File is too large
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/files/{fileId}', requirements: ['fileId' => '\d+'])]
	public function analyze(int $fileId): DataResponse {
		return $this->handle(fn (string $userId) => $this->service->analyze($userId, $fileId)->jsonSerialize());
	}

	/**
	 * Remove metadata from a file
	 *
	 * The original is kept in the file's version history.
	 *
	 * @param int $fileId ID of the file
	 * @return DataResponse<Http::STATUS_OK, MetaScrubResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{message: string}, array{}>
	 *
	 * 200: Metadata removed (or file was already clean)
	 * 400: File is not a supported image or is damaged
	 * 403: Not allowed to modify the file
	 * 404: File not found
	 * 413: File is too large
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/files/{fileId}/scrub', requirements: ['fileId' => '\d+'])]
	public function scrub(int $fileId): DataResponse {
		return $this->handle(function (string $userId) use ($fileId) {
			$result = $this->service->scrub($userId, $fileId);
			return $result['report']->jsonSerialize() + [
				'changed' => $result['changed'],
				'size' => $result['size'],
				'mtime' => $result['mtime'],
				'etag' => $result['etag'],
			];
		});
	}

	/**
	 * @param callable(string): array<string, mixed> $action
	 */
	private function handle(callable $action): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => $this->l10n->t('Not logged in')], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse($action($user->getUID()));
		} catch (NotFoundException) {
			return new DataResponse(['message' => $this->l10n->t('File not found')], Http::STATUS_NOT_FOUND);
		} catch (NotPermittedException) {
			return new DataResponse(['message' => $this->l10n->t('You do not have permission to change this file')], Http::STATUS_FORBIDDEN);
		} catch (UnsupportedFormatException) {
			return new DataResponse(['message' => $this->l10n->t('Only JPEG, PNG and WebP images are supported')], Http::STATUS_BAD_REQUEST);
		} catch (CorruptFileException) {
			return new DataResponse(['message' => $this->l10n->t('The image appears to be damaged, so it was left untouched')], Http::STATUS_BAD_REQUEST);
		} catch (FileTooLargeException) {
			return new DataResponse([
				'message' => $this->l10n->t('Files larger than %s MB are not processed', [(string)($this->service->getMaxSize() / 1024 / 1024)]),
			], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
	}
}
