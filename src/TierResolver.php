<?php

namespace Ramon\Verified;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Single source of truth for "what tier (if any) does this user belong to".
 *
 * Before this class existed, the same resolution logic was duplicated between
 * `Api\UserResourceFields::resolveTierId` and `Api\Controller\ListApprovedUsersController::resolveTierId`.
 * The duplication was a maintenance hazard — a fix to the manual-vs-auto
 * precedence rule in one path could (and did) drift from the other and
 * silently change which users showed as verified in the admin list while
 * the schema attribute reported something else.
 *
 * The class also caches the parsed tier list per instance: the constructor
 * costs are paid once per HTTP request, every subsequent lookup is a pure
 * array walk.
 */
class TierResolver
{
    /** @var array<int, array>|null */
    protected ?array $tiers = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * A user is "verified" when they hold a tier — either explicitly assigned
     * by an admin (column `verified_tier` filled, or legacy `is_verified=1`)
     * or implicitly via group auto-grant configured per tier.
     */
    public function isVerified(User $user): bool
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
    public function resolveTierId(User $user): ?string
    {
        $tiers = $this->tiers();
        if (empty($tiers)) {
            return null;
        }

        // Manual verification path requires `is_verified=true`. The
        // `verified_tier` column alone is NOT enough — verify/unverify flows
        // always toggle both together, but stale rows from older bugs or
        // manual SQL edits can leave a tier id behind on an unverified user.
        // Treating verified_tier as the source of truth would silently keep
        // those users branded as verified forever.
        if ((bool) $user->is_verified) {
            $manual = is_string($user->verified_tier) && $user->verified_tier !== ''
                ? strtolower($user->verified_tier)
                : null;

            if ($manual !== null) {
                $tier = TierConfig::findById($tiers, $manual);
                if ($tier) return $tier['id'];
                // Manual tier id was deleted from settings — fall back to
                // the default tier so the badge stays visible.
            }

            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        // Not manually verified — try auto-grant by group membership.
        $autoTier = $this->resolveAutoTier($user);
        return $autoTier['id'] ?? null;
    }

    /**
     * Return the tier this user would receive purely through group auto-grant,
     * regardless of `is_verified`. Used by the self-revoke flow to surface
     * the post-revoke state: when an admin unflips `is_verified` but the user
     * is also in an `autoGroups` member group, the badge survives via the
     * group path — callers must inform the user (audit L-self-revoke).
     *
     * @return array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[]}|null
     */
    public function resolveAutoTier(User $user): ?array
    {
        $tiers = $this->tiers();
        if (empty($tiers)) return null;

        $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        return TierConfig::autoTierFor($tiers, $userGroupIds);
    }

    /**
     * @return array<int, array>
     */
    public function tiers(): array
    {
        return $this->tiers ??= TierConfig::fromSettings($this->settings);
    }
}
