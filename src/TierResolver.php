<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;

/**
 * Fonte única de "qual tier este usuário tem". A lista de tiers é parseada uma
 * vez por instância.
 */
class TierResolver
{
    /** @var array<int, array>|null */
    protected ?array $tiers = null;

    /**
     * Fila da relação `verification`: o getter enfileira na fase síncrona da
     * serialização e a primeira leitura carrega todos numa consulta só, como o
     * `EloquentBuffer` do core.
     *
     * @var array<int, User>
     */
    private array $pending = [];

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
     * Manual ganha do auto e exige `is_verified=true`; um `verified_tier` que foi
     * removido da config cai no default. Com o tombstone `auto_revoked_at`, o
     * usuário optou por sair e não recebe auto-grant.
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
     * Input ausente ou inválido cai no default (`blue`) ou no primeiro tier: a
     * aprovação nunca trava por causa do tier. `null` sem tiers configurados.
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
     * Ignora `is_verified`: serve para avisar, no self-revoke, que o badge sobrevive
     * via grupo.
     *
     * @return array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[],badgeEnabled:bool,badgeSvg:string}|null
     */
    public function resolveAutoTier(User $user): ?array
    {
        $tiers = $this->tiers();

        // Sem tier com grupos automáticos não há o que procurar, e o usuário
        // não precisa carregar a relação `groups` (uma consulta por usuário).
        if (! array_filter($tiers, fn (array $tier) => $tier['autoGroups'] !== [])) {
            return null;
        }

        $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        return TierConfig::autoTierFor($tiers, $userGroupIds);
    }

    /** Garante a relação `verification` carregada (esvaziando a fila em lote). */
    public function ensureLoaded(User $user): void
    {
        $this->loadVerification($user);
    }

    /** Enfileira o usuário para o carregamento em lote de `verification`. */
    public function defer(User $user): void
    {
        if ($user->exists && ! $user->relationLoaded('verification')) {
            $this->pending[spl_object_id($user)] = $user;
        }
    }

    /**
     * @return array<int, array>
     */
    public function tiers(): array
    {
        return $this->tiers ??= TierConfig::fromSettings($this->settings);
    }

    /**
     * Usa a relação se já carregada; senão consulta direto (listeners e jobs).
     */
    private function loadVerification(User $user): ?UserVerification
    {
        if (! $user->relationLoaded('verification') && $this->pending !== []) {
            $batch = array_values($this->pending);
            $this->pending = [];
            $user->newCollection($batch)->load('verification');
        }

        if ($user->relationLoaded('verification')) {
            $loaded = $user->getRelation('verification');
            return $loaded instanceof UserVerification ? $loaded : null;
        }

        $row = UserVerification::query()->where('user_id', $user->id)->first();
        $user->setRelation('verification', $row);

        return $row instanceof UserVerification ? $row : null;
    }
}
