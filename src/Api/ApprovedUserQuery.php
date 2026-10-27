<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Verified\TierConfig;
use Ramon\Verified\TierResolver;

/**
 * Listagem de verificados do admin: manuais (`is_verified=1`) unidos aos
 * auto-verificados pelos `autoGroups` dos tiers.
 *
 * O filtro por tier não cabe numa única query paginável, porque o tier efetivo
 * do ramo auto só se resolve em PHP. Ver `pageWithTierFilter()`.
 */
class ApprovedUserQuery
{
    public const TIER_FILTER_CHUNK = 200;

    /**
     * Teto do walk em PHP do ramo auto (5 chunks). Ao atingi-lo, `truncated=true`
     * pede que o admin refine a busca. O ramo manual é SQL puro e não tem teto.
     */
    public const TIER_FILTER_AUTO_WALK_CAP = 1000;

    /**
     * Cache de instância, não `static`: em processos longos (Octane, filas) um
     * static sobreviveria entre requests e ficaria obsoleto após uma migração.
     *
     * @var string[]|null
     */
    private ?array $searchableColumnsCache = null;

    public function __construct(
        protected TierResolver $tierResolver,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * `nickname` só entra com flarum/nicknames ativa. Consultar o `ExtensionManager`
     * evita o SELECT em INFORMATION_SCHEMA do `hasColumn()`. `display_name` não é
     * coluna no Flarum 2.
     *
     * @return string[]
     */
    public function searchableColumns(): array
    {
        if ($this->searchableColumnsCache !== null) {
            return $this->searchableColumnsCache;
        }

        $columns = ['username', 'email'];
        if ($this->extensions->isEnabled('flarum-nicknames')) {
            $columns[] = 'nickname';
        }

        return $this->searchableColumnsCache = $columns;
    }

    /**
     * @return array{users: Collection<int, User>, total: int, truncated: bool}
     */
    public function page(ApprovedUserCriteria $criteria): array
    {
        $autoVerifiedGroupIds = $this->collectAutoGroupIds();
        $adminAllowed = in_array(Group::ADMINISTRATOR_ID, $autoVerifiedGroupIds, true);

        if ($criteria->tierFilter !== '') {
            return $this->pageWithTierFilter($criteria, $adminAllowed);
        }

        return $this->pageWithoutTierFilter($criteria, $autoVerifiedGroupIds, $adminAllowed);
    }

    /**
     * @return int[]
     */
    public function autoVerifiedGroupIds(): array
    {
        return $this->collectAutoGroupIds();
    }

    /**
     * @return int[]
     */
    private function collectAutoGroupIds(): array
    {
        $tiers = $this->tierResolver->tiers();
        $ids = [];
        foreach ($tiers as $t) {
            foreach ($t['autoGroups'] as $gid) $ids[$gid] = true;
        }
        return array_keys($ids);
    }

    /**
     * @param int[] $autoVerifiedGroupIds
     * @return array{users: Collection<int, User>, total: int, truncated: bool}
     */
    private function pageWithoutTierFilter(
        ApprovedUserCriteria $criteria,
        array $autoVerifiedGroupIds,
        bool $adminAllowed
    ): array {
        $query = $this->baseApprovedQuery($autoVerifiedGroupIds, $adminAllowed);
        $this->applySearch($query, $criteria->q);

        $total = (clone $query)->count();
        $users = $this->orderByPresenceAndPaginate($query, $criteria);
        $users->load(['groups', 'verification']);

        return ['users' => $users, 'total' => $total, 'truncated' => false];
    }

    /**
     * Manuais primeiro, depois auto-tier, data de verificação e username.
     * `IS NULL ASC` em vez de `IS NOT NULL DESC`: o PostgreSQL não aceita predicado
     * booleano em ORDER BY. `user_id` dispensa qualificador porque só existe em
     * `user_verification`.
     *
     * @param Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Collection<int, \Flarum\User\User>
     */
    private function orderByPresenceAndPaginate(Builder $query, ApprovedUserCriteria $criteria)
    {
        return $query
            ->leftJoin('user_verification', 'user_verification.user_id', '=', 'users.id')
            ->select('users.*')
            ->orderByRaw('user_id IS NULL ASC')
            ->orderByDesc('user_verification.verified_at')
            ->orderBy('users.username', 'asc')
            ->skip($criteria->offset)
            ->take($criteria->limit)
            ->get();
    }

    /**
     * O ramo manual conta por `COUNT(*)` e pagina por `LIMIT/OFFSET`. O ramo auto
     * percorre os candidatos uma vez, até `TIER_FILTER_AUTO_WALK_CAP`, guardando só
     * os IDs da página. Como o bloco manual vem primeiro na união, a janela do ramo
     * auto é calculada em coordenadas locais.
     *
     * @return array{users: Collection<int, User>, total: int, truncated: bool}
     */
    private function pageWithTierFilter(
        ApprovedUserCriteria $criteria,
        bool $adminAllowed
    ): array {
        $tiers = $this->tierResolver->tiers();
        $needle = strtolower($criteria->tierFilter);
        $isDefaultTier   = $needle === TierConfig::DEFAULT_TIER_ID;
        $blueIsConfigured = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) !== null;

        $targetTier = TierConfig::findById($tiers, $needle);
        $targetTierGroupIds = $targetTier !== null ? $targetTier['autoGroups'] : [];

        $offset = $criteria->offset;
        $limit  = $criteria->limit;

        $manualQuery = $this->manualTierQuery($criteria, $needle, $isDefaultTier, $blueIsConfigured);
        $manualCount = (clone $manualQuery)->count();

        $autoCount = 0;
        $autoTruncated = false;
        $autoPageIds = [];

        if (! empty($targetTierGroupIds)) {
            $autoWindowStart  = max(0, $offset - $manualCount);
            $autoWindowLength = max(0, ($offset + $limit) - max($offset, $manualCount));

            $auto = $this->collectAutoTierPage(
                $criteria,
                $targetTierGroupIds,
                $adminAllowed,
                $needle,
                self::TIER_FILTER_AUTO_WALK_CAP,
                $autoWindowStart,
                $autoWindowLength
            );
            $autoCount     = $auto['count'];
            $autoTruncated = $auto['truncated'];
            $autoPageIds   = $auto['pageIds'];
        }

        $total     = $manualCount + $autoCount;
        $truncated = $autoTruncated;

        $ordered = [];

        $manualSkip = min($offset, $manualCount);
        $manualTake = max(0, min($offset + $limit, $manualCount) - $manualSkip);
        if ($manualTake > 0) {
            $manualPage = $manualQuery
                ->select('users.*')
                ->with(['groups', 'verification'])
                ->orderBy('user_verification.verified_at', 'desc')
                ->orderBy('users.username', 'asc')
                ->orderBy('users.id', 'asc')
                ->skip($manualSkip)
                ->take($manualTake)
                ->get();
            foreach ($manualPage as $row) {
                if ($row instanceof User) {
                    $ordered[] = $row;
                }
            }
        }

        if (! empty($autoPageIds)) {
            $autoFetched = User::query()
                ->with(['groups', 'verification'])
                ->whereIn('id', $autoPageIds)
                ->get()
                ->keyBy('id');
            foreach ($autoPageIds as $id) {
                $row = $autoFetched->get($id);
                if ($row instanceof User) {
                    $ordered[] = $row;
                }
            }
        }

        return [
            'users'     => collect($ordered),
            'total'     => $total,
            'truncated' => $truncated,
        ];
    }

    /**
     * Sem ordenação nem limite: o caller decide se conta ou pagina. `verified_tier`
     * NULL casa com o tier default.
     */
    private function manualTierQuery(
        ApprovedUserCriteria $criteria,
        string $needle,
        bool $isDefaultTier,
        bool $blueIsConfigured
    ): Builder {
        $query = User::query()
            ->join('user_verification', 'user_verification.user_id', '=', 'users.id')
            ->where('user_verification.is_verified', true)
            ->where(function (Builder $w) use ($needle, $isDefaultTier, $blueIsConfigured) {
                $w->where('user_verification.verified_tier', $needle);
                if ($isDefaultTier && $blueIsConfigured) {
                    $w->orWhereNull('user_verification.verified_tier');
                }
            });

        $this->applySearch($query, $criteria->q);

        return $query;
    }

    /**
     * Guarda no máximo `windowLength` IDs; a contagem segue até `$cap`, então a
     * memória não cresce com o conjunto.
     *
     * @param int[] $targetTierGroupIds
     * @return array{count: int, pageIds: int[], truncated: bool}
     */
    private function collectAutoTierPage(
        ApprovedUserCriteria $criteria,
        array $targetTierGroupIds,
        bool $adminAllowed,
        string $needle,
        int $cap,
        int $windowStart,
        int $windowLength
    ): array {
        $autoQuery = User::query()
            ->whereNotExists(function ($sub) {
                $sub->from('user_verification')
                    ->whereColumn('user_verification.user_id', 'users.id')
                    ->where('user_verification.is_verified', true);
            })
            ->whereExists(function ($sub) use ($targetTierGroupIds) {
                $sub->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->whereIn('group_user.group_id', $targetTierGroupIds);
            });

        if (! $adminAllowed) {
            $autoQuery->whereNotExists(function ($sub) {
                $sub->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->where('group_id', Group::ADMINISTRATOR_ID);
            });
        }

        $this->applySearch($autoQuery, $criteria->q);

        $matched   = 0;
        $pageIds   = [];
        $truncated = false;
        $windowEnd = $windowStart + $windowLength;

        $autoQuery
            ->orderBy('username', 'asc')
            ->orderBy('id', 'asc')
            ->chunkById(self::TIER_FILTER_CHUNK, function ($users) use (
                $needle,
                $cap,
                $windowStart,
                $windowEnd,
                &$matched,
                &$pageIds,
                &$truncated
            ) {
                $users->load(['groups', 'verification']);
                foreach ($users as $user) {
                    if ($this->tierResolver->resolveTierId($user) !== $needle) {
                        continue;
                    }
                    if ($matched >= $windowStart && $matched < $windowEnd) {
                        $pageIds[] = (int) $user->id;
                    }
                    $matched++;
                    if ($matched >= $cap) {
                        $truncated = true;
                        return false;
                    }
                }
                return true;
            });

        return ['count' => $matched, 'pageIds' => $pageIds, 'truncated' => $truncated];
    }

    /**
     * O ramo auto exclui quem tem tombstone (`auto_revoked_at`): a pessoa continua
     * no grupo, mas pediu para sair, e o `TierResolver` já a trata como não verificada.
     *
     * @param int[] $autoVerifiedGroupIds
     */
    private function baseApprovedQuery(array $autoVerifiedGroupIds, bool $adminAllowed): Builder
    {
        return User::query()->where(function (Builder $w) use ($autoVerifiedGroupIds, $adminAllowed) {
            $w->whereExists(function ($sub) {
                $sub->from('user_verification')
                    ->whereColumn('user_verification.user_id', 'users.id')
                    ->where('user_verification.is_verified', true);
            });

            if (empty($autoVerifiedGroupIds)) {
                return;
            }

            $w->orWhere(function (Builder $auto) use ($autoVerifiedGroupIds, $adminAllowed) {
                $auto->whereExists(function ($sub) use ($autoVerifiedGroupIds) {
                    $sub->from('group_user')
                        ->whereColumn('group_user.user_id', 'users.id')
                        ->whereIn('group_user.group_id', $autoVerifiedGroupIds);
                });

                $auto->whereNotExists(function ($sub) {
                    $sub->from('user_verification')
                        ->whereColumn('user_verification.user_id', 'users.id')
                        ->whereNotNull('user_verification.auto_revoked_at');
                });

                if (! $adminAllowed) {
                    $auto->whereNotExists(function ($sub) {
                        $sub->from('group_user')
                            ->whereColumn('group_user.user_id', 'users.id')
                            ->where('group_id', Group::ADMINISTRATOR_ID);
                    });
                }
            });
        });
    }

    /**
     * Escapa os wildcards do input antes de montar o LIKE.
     */
    private function applySearch(Builder $query, string $q): void
    {
        if ($q === '') return;

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $columns = $this->searchableColumns();

        $query->where(function (Builder $w) use ($like, $columns) {
            foreach ($columns as $i => $col) {
                if ($i === 0) {
                    $w->where($col, 'like', $like);
                } else {
                    $w->orWhere($col, 'like', $like);
                }
            }
        });
    }
}
