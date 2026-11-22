<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Metadata Scrubber contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MetaScrub\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\MetaScrub\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Registers our file action when the Files app loads.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		// Init scripts run before the Files app mounts, so the action is
		// registered in time. The script itself is tiny; the dialog is
		// lazy-loaded only when the action is used.
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-init');
	}
}
