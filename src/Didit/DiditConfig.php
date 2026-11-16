<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Didit;

use Flarum\Settings\SettingsRepositoryInterface;

class DiditConfig
{
    public const ENABLED = 'ramon-verified.didit_enabled';
    public const API_KEY = 'ramon-verified.didit_api_key';
    public const WEBHOOK_SECRET = 'ramon-verified.didit_webhook_secret';
    public const WORKFLOW_ID = 'ramon-verified.didit_workflow_id';

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    public function apiKey(): string
    {
        return trim((string) $this->settings->get(self::API_KEY));
    }

    public function webhookSecret(): string
    {
        return trim((string) $this->settings->get(self::WEBHOOK_SECRET));
    }

    /**
     * Vazio usa o workflow marcado como padrão no console da Didit.
     */
    public function workflowId(): ?string
    {
        $id = trim((string) $this->settings->get(self::WORKFLOW_ID));

        return $id === '' ? null : $id;
    }

    /**
     * Ligada e com a chave da API: só então o fluxo passa a ser pela Didit.
     */
    public function active(): bool
    {
        return $this->enabled() && $this->apiKey() !== '';
    }
}
