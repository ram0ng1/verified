<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;

class UserResourceFields
{
    /** @var array<int,bool>|null Per-request cache of users with a pending request. */
    protected ?array $pendingUserIds = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tiers
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isVerified')
                ->get(fn (User $user) => $this->tiers->isVerified($user)),

            Schema\DateTime::make('verifiedAt')
                ->property('verified_at')
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

                    // Admin can close intake of new requests entirely.
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

                    return (bool) $user->is_verified;
                }),
        ];
    }

    /**
     * Per-request membership check for "user has any pending verification
     * request". On the first call we preload the WHOLE set of pending user
     * ids in one query — pending is a transient state, so the row count is
     * bounded even on busy forums. Subsequent lookups are O(1) hash hits.
     *
     * Without this cache the field getters fired one EXISTS query per row
     * during admin user listings (audit F-N+1), turning a 50-row page into
     * 50 round-trips against `verification_requests`.
     */
    protected function userHasPending(int $userId): bool
    {
        if ($this->pendingUserIds === null) {
            $this->pendingUserIds = VerificationRequest::query()
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        return isset($this->pendingUserIds[$userId]);
    }
}
