<?php

namespace Ramon\Verified\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;
use Ramon\Verified\TierResolver;

/**
 * Bloqueia save e delete de avatar para o próprio usuário verificado quando
 * `lock_avatar` está ativo. Usa `TierResolver::isVerified` para também
 * cobrir auto-tiers por grupo.
 *
 * Override moderativo: qualquer ator com `editCredentials` no alvo passa
 * — inclui admins (sempre têm) e moderadores em fóruns que concederam
 * essa permissão ao grupo de moderação. Sem esse override o lock travava
 * intervenção legítima de equipe.
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
