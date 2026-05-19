<?php

namespace Ramon\Verified\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;
use Ramon\Verified\TierResolver;

/**
 * Bloqueia save e delete de avatar para verificados não-admin quando
 * `lock_avatar` está ativo. Usa `TierResolver::isVerified` para também
 * cobrir auto-tiers por grupo.
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

        if ($actor->isAdmin()) {
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
