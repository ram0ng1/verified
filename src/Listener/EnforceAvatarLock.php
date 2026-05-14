<?php

namespace Ramon\Verified\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;

class EnforceAvatarLock
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Block BOTH avatar saves and avatar deletes for verified non-admin
     * users when the lock setting is on. Listening only to AvatarSaving
     * leaves the user able to `DELETE /api/users/{id}/avatar` and reach
     * an avatar-less state (audit N5) — defeating the impersonation
     * defense the lock is supposed to provide. Both core events share
     * the `(User $user, User $actor)` shape, so one handler covers both.
     */
    public function handle(AvatarSaving|AvatarDeleting $event): void
    {
        if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
            return;
        }

        $user = $event->user;
        $actor = $event->actor;

        // Guest actors should never reach the avatar-save flow; auth is
        // enforced upstream. Exit early rather than running the verified-
        // user check on the implicit guest identity (audit F16).
        if ($actor->isGuest()) {
            return;
        }

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
