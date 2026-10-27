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
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;
use Ramon\Verified\TierResolver;

/**
 * Com `lock_avatar` ativo, o verificado (inclusive por auto-tier) não troca nem
 * apaga o próprio avatar. Quem tem `editCredentials` sobre o alvo passa, para
 * não travar intervenções da equipe.
 */
class EnforceAvatarLock
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected TierResolver $tierResolver
    ) {
    }

    public function handle(AvatarSaving|AvatarDeleting $event): void
    {
        if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
            return;
        }

        $user = $event->user;
        $actor = $event->actor;

        if ($actor->isGuest()) {
            return;
        }

        if ($actor->can('editCredentials', $user)) {
            return;
        }

        if (! $this->tierResolver->isVerified($user)) {
            return;
        }

        throw new ValidationException([
            'avatar' => $this->translator->trans('ramon-verified.api.avatar_locked'),
        ]);
    }
}
