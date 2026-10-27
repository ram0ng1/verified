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
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\VerifiedStatus;

class UserResourceFields
{
    protected ?bool $nicknamesEnabled = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected VerifiedStatus $verifiedStatus,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * A coluna `nickname` sobrevive à desativação do flarum/nicknames, então o
     * apelido só sai com a extensão ativa.
     */
    protected function nicknamesEnabled(): bool
    {
        return $this->nicknamesEnabled ??= $this->extensions->isEnabled('flarum-nicknames');
    }

    protected function batch(Context $context): UserVerificationBatch
    {
        return UserVerificationBatch::forRequest($context->request, $this->settings);
    }

    public function __invoke(): array
    {
        return [
            // Enfileira na fase síncrona e resolve adiado: quando o valor é
            // lido, todos os usuários do documento já estão na fila e a relação
            // `verification` sai numa consulta só.
            Schema\Boolean::make('isVerified')
                ->get(function (User $user, Context $context) {
                    $tiers = $this->batch($context)->tiers();
                    $tiers->defer($user);

                    return fn () => $tiers->isVerified($user);
                }),

            Schema\DateTime::make('verifiedAt')
                ->get(function (User $user, Context $context) {
                    $tiers = $this->batch($context)->tiers();
                    $tiers->defer($user);

                    return function () use ($user, $tiers) {
                        $tiers->ensureLoaded($user);

                        return $this->verifiedStatus->verifiedAt($user);
                    };
                })
                ->nullable(),

            Schema\Str::make('verifiedTier')
                ->get(function (User $user, Context $context) {
                    $tiers = $this->batch($context)->tiers();
                    $tiers->defer($user);

                    return fn () => $tiers->resolveTierId($user);
                })
                ->nullable(),

            // Apelido do flarum/nicknames para a segunda linha do popover. Só sai
            // para verificados e quando difere do username.
            Schema\Str::make('verifiedNickname')
                ->visible(fn () => $this->nicknamesEnabled())
                ->get(function (User $user, Context $context) {
                    $tiers = $this->batch($context)->tiers();
                    $tiers->defer($user);

                    return function () use ($user, $tiers) {
                        $nickname = $user->getAttribute('nickname');
                        if (! is_string($nickname) || trim($nickname) === '' || $nickname === $user->username) {
                            return null;
                        }

                        return $tiers->isVerified($user) ? $nickname : null;
                    };
                })
                ->nullable(),

            Schema\Boolean::make('canRequestVerification')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ($this->batch($context)->tiers()->isVerified($user)) {
                        return false;
                    }

                    if (! $actor->hasPermission('verified.request')) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.requests_open')) {
                        return false;
                    }

                    $batch = $this->batch($context);
                    $batch->queuePending((int) $user->id);

                    return fn () => ! $batch->hasPending((int) $user->id);
                }),

            Schema\Boolean::make('hasPendingVerificationRequest')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || ($actor->id !== $user->id && ! $actor->isAdmin())) {
                        return false;
                    }

                    $batch = $this->batch($context);
                    $batch->queuePending((int) $user->id);

                    return fn () => $batch->hasPending((int) $user->id);
                }),

            Schema\Boolean::make('isAvatarLocked')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id || $actor->isAdmin()) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
                        return false;
                    }

                    return $this->batch($context)->tiers()->isVerified($user);
                }),
        ];
    }
}
