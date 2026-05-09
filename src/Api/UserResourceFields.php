<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;

class UserResourceFields
{
    /** @var array<int, array>|null Cache of parsed tier list. */
    protected ?array $tiers = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isVerified')
                ->get(fn (User $user) => $this->isVerified($user)),

            Schema\DateTime::make('verifiedAt')
                ->property('verified_at')
                ->nullable(),

            Schema\Str::make('verifiedTier')
                ->get(fn (User $user) => $this->resolveTierId($user))
                ->nullable(),

            Schema\Boolean::make('canRequestVerification')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ($this->isVerified($user)) {
                        return false;
                    }

                    if (! $actor->hasPermission('verified.request')) {
                        return false;
                    }

                    // Admin can close intake of new requests entirely.
                    if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
                        return false;
                    }

                    $hasPending = VerificationRequest::query()
                        ->where('user_id', $user->id)
                        ->where('status', VerificationRequest::STATUS_PENDING)
                        ->exists();

                    return ! $hasPending;
                }),

            Schema\Boolean::make('hasPendingVerificationRequest')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || ($actor->id !== $user->id && ! $actor->isAdmin())) {
                        return false;
                    }

                    return VerificationRequest::query()
                        ->where('user_id', $user->id)
                        ->where('status', VerificationRequest::STATUS_PENDING)
                        ->exists();
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
     * A user is "verified" when they hold a tier — either explicitly assigned
     * by an admin (column `verified_tier` filled, or legacy `is_verified=1`)
     * or implicitly via group auto-grant configured per tier.
     */
    protected function isVerified(User $user): bool
    {
        if ((bool) $user->is_verified) {
            return true;
        }

        return $this->resolveTierId($user) !== null;
    }

    /**
     * Resolve the user's effective tier id. Manual assignment beats auto.
     *
     * Returns null when the user has no tier (i.e. is not verified).
     */
    protected function resolveTierId(User $user): ?string
    {
        $tiers = $this->getTiers();
        if (empty($tiers)) {
            return null;
        }

        $manual = is_string($user->verified_tier) && $user->verified_tier !== ''
            ? strtolower($user->verified_tier)
            : null;

        if ($manual !== null) {
            $tier = TierConfig::findById($tiers, $manual);
            if ($tier) return $tier['id'];
            // Manual tier id was deleted from settings — fall back to the
            // default tier so the badge stays visible.
            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        // Try auto-grant by group membership.
        $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        $autoTier = TierConfig::autoTierFor($tiers, $userGroupIds);
        if ($autoTier) {
            return $autoTier['id'];
        }

        // Legacy fallback: a user marked verified before tiers existed has
        // `is_verified=1` but no tier and no auto match — surface the default.
        if ((bool) $user->is_verified) {
            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        return null;
    }

    /**
     * @return array<int, array>
     */
    protected function getTiers(): array
    {
        if ($this->tiers === null) {
            $this->tiers = TierConfig::fromSettings($this->settings);
        }
        return $this->tiers;
    }
}
