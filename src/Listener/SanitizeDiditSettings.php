<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\Event\Saving;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditConfig;

/**
 * Chaves da Didit chegam do campo de texto do admin: tira espaços colados
 * junto e recusa um workflow que não seja UUID (ele vai no corpo da API).
 */
class SanitizeDiditSettings
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(Saving $event): void
    {
        foreach ([DiditConfig::API_KEY, DiditConfig::WEBHOOK_SECRET, DiditConfig::WORKFLOW_ID] as $key) {
            if (array_key_exists($key, $event->settings)) {
                $event->settings[$key] = trim((string) $event->settings[$key]);
            }
        }

        $workflow = $event->settings[DiditConfig::WORKFLOW_ID] ?? '';
        if ($workflow !== '' && ! DiditClient::isSessionId($workflow)) {
            throw new ValidationException([
                DiditConfig::WORKFLOW_ID => $this->translator->trans('ramon-verified.api.didit.invalid_workflow'),
            ]);
        }
    }
}
