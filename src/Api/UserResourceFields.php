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
    /**
     * Set de user_ids com request pendente, carregado em batch na primeira
     * leitura (§38.1). `null` enquanto não inicializado; um `array<int, true>`
     * depois — `isset($map[$id])` é O(1).
     *
     * @var array<int, true>|null
     */
    protected ?array $pendingUserIds = null;

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
     * Lookup O(1) contra o set pré-carregado. A primeira chamada dispara UMA
     * query `SELECT DISTINCT user_id FROM verification_requests WHERE
     * status='pending'` — o índice composto `(user_id, status)` cobre por
     * inteiro, e o resultado é bounded (pending tipicamente <100 mesmo em
     * fóruns grandes). Substitui o EXISTS-por-linha que tornava o §38.1
     * N+1 visível em listagens de admin com page[limit]=50.
     */
    protected function userHasPending(int $userId): bool
    {
        if ($this->pendingUserIds === null) {
            $this->pendingUserIds = [];
            VerificationRequest::query()
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->distinct()
                ->pluck('user_id')
                ->each(function ($id) {
                    $this->pendingUserIds[(int) $id] = true;
                });
        }

        return isset($this->pendingUserIds[$userId]);
    }
}
