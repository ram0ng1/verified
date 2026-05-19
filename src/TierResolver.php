<?php

namespace Ramon\Verified;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;

/**
 * Fonte única de "qual tier (se algum) este usuário tem". A lista de tiers
 * é cacheada por instância — o parse das settings é pago uma vez por request,
 * lookups subsequentes são walk de array. Lê o estado verified via relação
 * `verification` (companion table) com fallback à query direta
 * quando a relação não foi eager-loaded.
 */
class TierResolver
{
    /** @var array<int, array>|null */
    protected ?array $tiers = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function isVerified(User $user): bool
    {
        $ver = $this->loadVerification($user);
        if ($ver && (bool) $ver->is_verified) {
            return true;
        }

        return $this->resolveTierId($user) !== null;
    }

    /**
     * Resolve o tier efetivo. Manual ganha do auto. Manual exige
     * `verification.is_verified=true` — coluna `verified_tier` sozinha não
     * basta: tier configurado e removido em runtime cai no default.
     *
     * Tombstone de opt-out: quando há row com `auto_revoked_at` set e
     * `is_verified=false`, o usuário pediu para sair do auto-tier — não
     * devolvemos auto-grant via grupo. `mark()` limpa o tombstone ao
     * reverificar manualmente; `clear()` também (volta ao estado original).
     */
    public function resolveTierId(User $user): ?string
    {
        $tiers = $this->tiers();
        if (empty($tiers)) {
            return null;
        }

        $ver = $this->loadVerification($user);

        if ($ver && (bool) $ver->is_verified) {
            $manual = is_string($ver->verified_tier) && $ver->verified_tier !== ''
                ? strtolower($ver->verified_tier)
                : null;

            if ($manual !== null) {
                $tier = TierConfig::findById($tiers, $manual);
                if ($tier) return $tier['id'];
            }

            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        if ($ver && $ver->auto_revoked_at !== null) {
            return null;
        }

        $autoTier = $this->resolveAutoTier($user);
        return $autoTier['id'] ?? null;
    }

    /**
     * Resolve o tier requisitado em um body de aprovação contra a lista
     * configurada. Input ausente ou inválido cai no default (`blue`) ou no
     * primeiro tier configurado — aprovação nunca trava por causa do tier.
     * Devolve `null` quando o admin não configurou nenhum tier.
     */
    public function resolveRequestedTierId(?string $requested): ?string
    {
        $tiers = $this->tiers();
        if (empty($tiers)) {
            return null;
        }

        $requested = $requested !== null ? trim($requested) : null;

        if ($requested !== null && $requested !== '') {
            $found = TierConfig::findById($tiers, $requested);
            if ($found) return $found['id'];
        }

        $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
        return $fallback['id'];
    }

    /**
     * Tier que o usuário receberia exclusivamente por auto-grant de grupo,
     * independentemente de `is_verified`. Usado no fluxo de self-revoke
     * para informar o caller que o badge sobrevive via grupo.
     *
     * @return array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[],badgeEnabled:bool,badgeSvg:string}|null
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

    /**
     * Carrega a relação `verification` se já tiver sido eager-loaded; caso
     * contrário consulta a tabela diretamente. Cobre tanto reads vindos de
     * resources (que devem `eagerLoad('verification')`) quanto reads
     * pontuais em listeners/jobs.
     */
    private function loadVerification(User $user): ?UserVerification
    {
        if ($user->relationLoaded('verification')) {
            $loaded = $user->getRelation('verification');
            return $loaded instanceof UserVerification ? $loaded : null;
        }

        $row = UserVerification::query()->where('user_id', $user->id)->first();
        $user->setRelation('verification', $row);

        return $row instanceof UserVerification ? $row : null;
    }
}
