<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

class UserResourceFields
{
    /** @var array<int, bool> Cache por requisição com resultado do EXISTS. */
    protected array $pendingCache = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tiers,
        protected VerifiedStatus $verifiedStatus
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isVerified')
                ->get(fn (User $user) => $this->tiers->isVerified($user)),

            Schema\DateTime::make('verifiedAt')
                ->get(fn (User $user) => $this->verifiedStatus->verifiedAt($user))
                ->nullable(),

            Schema\Str::make('verifiedTier')
                ->get(fn (User $user) => $this->tiers->resolveTierId($user))
                ->nullable(),

            Schema\Boolean::make('canRequestVerification')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ($this->tiers->isVerified($user)) {
                        return false;
                    }

                    if (! $actor->hasPermission('verified.request')) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
                        return false;
                    }

                    return ! $this->userHasPending((int) $user->id);
                }),

            Schema\Boolean::make('hasPendingVerificationRequest')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || ($actor->id !== $user->id && ! $actor->isAdmin())) {
                        return false;
                    }

                    return $this->userHasPending((int) $user->id);
                }),

            Schema\Boolean::make('isAvatarLocked')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ((bool) $actor->isAdmin()) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
                        return false;
                    }

                    return $this->tiers->isVerified($user);
                }),
        ];
    }

    /**
     * EXISTS por usuário com cache local da instância. Apenas usuários que
     * passam pelos gates de permissão acima chegam aqui — admin listing
     * dispara no máximo 1 query por linha contra `verification_requests`,
     * cacheada para evitar duplicatas. Sem materialização de todo o
     * conjunto pending na memória.
     */
    protected function userHasPending(int $userId): bool
    {
        if (array_key_exists($userId, $this->pendingCache)) {
            return $this->pendingCache[$userId];
        }

        return $this->pendingCache[$userId] = VerificationRequest::query()
            ->where('user_id', $userId)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->exists();
    }
}
