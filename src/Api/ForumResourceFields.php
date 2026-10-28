<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Campos do `ForumResource` só para usuários autenticados, fora do payload
 * anônimo. As permissões espelham `VerifyUserController::assertCanActOn`.
 */
class ForumResourceFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        $loggedIn = fn (object $model, Context $context) => ! $context->getActor()->isGuest();

        return [
            Schema\Boolean::make('canVerifyUsers')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('verified.verifyUsers'))
                ->visible($loggedIn),

            Schema\Boolean::make('canSelfRevokeVerification')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('verified.selfRevoke'))
                ->visible($loggedIn),

            Schema\Boolean::make('ramonVerifiedRequestsOpen')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.requests_open', true))
                ->visible($loggedIn),

            Schema\Boolean::make('ramonVerifiedRequireDocument')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.require_document', false))
                ->visible($loggedIn),

            Schema\Boolean::make('ramonVerifiedLockAvatar')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.lock_avatar', false))
                ->visible($loggedIn),

            Schema\Arr::make('ramonVerifiedDocumentTypes')
                ->get(fn () => $this->parseDocumentTypes())
                ->visible($loggedIn),
        ];
    }

    /**
     * Descarta linhas malformadas e limita o tamanho de id e label.
     *
     * @return array<int, array{id: string, label: string}>
     */
    private function parseDocumentTypes(): array
    {
        $raw = $this->settings->get('ramon-verified.document_types');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($list)) return [];

        $clean = [];
        foreach ($list as $row) {
            if (! is_array($row)) continue;
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($id === '' || $label === '') continue;
            $clean[] = [
                'id'    => mb_substr($id, 0, 32),
                'label' => mb_substr($label, 0, 64),
            ];
        }

        return $clean;
    }
}
