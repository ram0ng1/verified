<?php

namespace Ramon\Verified\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarSaving;

class EnforceAvatarLock
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(AvatarSaving $event): void
    {
        if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
            return;
        }

        $user = $event->user;
        $actor = $event->actor;

        if ($actor->isAdmin()) {
            return;
        }

        if (! (bool) $user->is_verified) {
            return;
        }

        throw new ValidationException([
            'avatar' => $this->translator->trans('ramon-verified.api.avatar_locked'),
        ]);
    }
}
