<?php

namespace Ramon\Verified\Api;

use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Verified\TierConfig;
use Ramon\Verified\TierResolver;

/**
 * Constrói as queries de listagem dos "verificados aprovados" para o admin.
 * Une o conjunto manual (`is_verified=1`) com o conjunto auto-verificado
 * (membros de `autoGroups` de algum tier configurado).
 *
 * O caso difícil é o filtro por tier:
 *   - Manual fast path: SQL puro (LIMIT/OFFSET no DB).
 *   - Auto fast path: o tier efetivo depende da ordem de prioridade dos
 *     `autoGroups`, então a resolução final precisa rodar em PHP. Para
 *     evitar materializar todos os IDs do conjunto auto-verificado em
 *     um array (audit T1), `chunkById` para no momento em que já houver
 *     IDs suficientes para satisfazer `offset + limit + 1`; o total é
 *     aproximado por `TIER_FILTER_TOTAL_CAP`.
 */
class ApprovedUserQuery
{
    public const TIER_FILTER_CHUNK     = 200;
    public const TIER_FILTER_TOTAL_CAP = 5000;

    public function __construct(
        protected TierResolver $tierResolver,
        protected ConnectionResolverInterface $connection
    ) {
    }

    /** @return string[] */
    public function searchableColumns(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $columns = ['username', 'email'];
        $schema  = $this->connection->connection()->getSchemaBuilder();
        foreach (['nickname', 'display_name'] as $optional) {
            if ($schema->hasColumn('users', $optional)) {
                $columns[] = $optional;
            }
        }

        return $cached = $columns;
    }

    /**
     * @return array{users: Collection<int, User>, total: int, truncated: bool}
     */
    public function page(ApprovedUserCriteria $criteria): array
    {
        $autoVerifiedGroupIds = $this->collectAutoGroupIds();
        $adminAllowed = in_array(Group::ADMINISTRATOR_ID, $autoVerifiedGroupIds, true);

        if ($criteria->tierFilter !== '') {
            return $this->pageWithTierFilter($criteria, $autoVerifiedGroupIds, $adminAllowed);
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
        $users = $query
            ->leftJoin('user_verification', 'user_verification.user_id', '=', 'users.id')
            ->select('users.*')
            ->orderByRaw('user_verification.user_id IS NOT NULL DESC')
            ->orderByDesc('user_verification.verified_at')
            ->orderBy('users.username', 'asc')
            ->skip($criteria->offset)
            ->take($criteria->limit)
            ->get();
        $users->load(['groups', 'verification']);

        return ['users' => $users, 'total' => $total, 'truncated' => false];
    }

    /**
     * @param int[] $autoVerifiedGroupIds
     * @return array{users: Collection<int, User>, total: int, truncated: bool}
     */
    private function pageWithTierFilter(
        ApprovedUserCriteria $criteria,
        array $autoVerifiedGroupIds,
        bool $adminAllowed
    ): array {
        $tiers = $this->tierResolver->tiers();
        $needle = strtolower($criteria->tierFilter);
        $isDefaultTier   = $needle === TierConfig::DEFAULT_TIER_ID;
        $blueIsConfigured = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) !== null;

        $manualIds = $this->collectManualTierIds($criteria, $needle, $isDefaultTier, $blueIsConfigured);

        $cap = self::TIER_FILTER_TOTAL_CAP;
        $truncated = false;
        $autoIds = [];
        $manualCount = count($manualIds);

        if (! empty($autoVerifiedGroupIds) && $manualCount < $cap) {
            $remainingCap = $cap - $manualCount;
            $autoIds = $this->collectAutoTierIds(
                $criteria,
                $autoVerifiedGroupIds,
                $adminAllowed,
                $needle,
                $remainingCap,
                $truncated
            );
        }

        $matchingIds = array_merge($manualIds, $autoIds);
        $total = count($matchingIds);
        $pageIds = array_slice($matchingIds, $criteria->offset, $criteria->limit);

        if (empty($pageIds)) {
            return [
                'users'     => collect(),
                'total'     => $total,
                'truncated' => $truncated,
            ];
        }

        $fetched = User::query()
            ->with(['groups', 'verification'])
            ->whereIn('id', $pageIds)
            ->get();

        $indexed = $fetched->keyBy('id');
        $users = $fetched->newCollection();
        foreach ($pageIds as $id) {
            $row = $indexed->get($id);
            if ($row !== null) {
                $users->push($row);
            }
        }

        return [
            'users'     => $users,
            'total'     => $total,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return int[]
     */
    private function collectManualTierIds(
        ApprovedUserCriteria $criteria,
        string $needle,
        bool $isDefaultTier,
        bool $blueIsConfigured
    ): array {
        $manualQuery = User::query()
            ->join('user_verification', 'user_verification.user_id', '=', 'users.id')
            ->where('user_verification.is_verified', true)
            ->where(function (Builder $w) use ($needle, $isDefaultTier, $blueIsConfigured) {
                $w->where('user_verification.verified_tier', $needle);
                if ($isDefaultTier && $blueIsConfigured) {
                    $w->orWhereNull('user_verification.verified_tier');
                }
            });
        $this->applySearch($manualQuery, $criteria->q);

        return $manualQuery
            ->orderBy('user_verification.verified_at', 'desc')
            ->orderBy('users.username', 'asc')
            ->orderBy('users.id', 'asc')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Caminho auto-only com cap. Para assim que a contagem coletada chega
     * ao limite — quando truncated=true, o admin precisa refinar a busca.
     *
     * @param int[] $autoVerifiedGroupIds
     * @return int[]
     */
    private function collectAutoTierIds(
        ApprovedUserCriteria $criteria,
        array $autoVerifiedGroupIds,
        bool $adminAllowed,
        string $needle,
        int $remainingCap,
        bool &$truncated
    ): array {
        $autoQuery = User::query()
            ->whereNotExists(function ($sub) {
                $sub->from('user_verification')
                    ->whereColumn('user_verification.user_id', 'users.id')
                    ->where('user_verification.is_verified', true);
            })
            ->whereExists(function ($sub) use ($autoVerifiedGroupIds) {
                $sub->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->whereIn('group_user.group_id', $autoVerifiedGroupIds);
            });

        if (! $adminAllowed) {
            $autoQuery->whereNotExists(function ($sub) {
                $sub->from('group_user')
                    ->whereColumn('group_user.user_id', 'users.id')
                    ->where('group_id', Group::ADMINISTRATOR_ID);
            });
        }

        $this->applySearch($autoQuery, $criteria->q);

        $autoIds = [];

        $autoQuery
            ->orderBy('username', 'asc')
            ->orderBy('id', 'asc')
            ->chunkById(self::TIER_FILTER_CHUNK, function ($users) use ($needle, $remainingCap, &$autoIds, &$truncated) {
                $users->load(['groups', 'verification']);
                foreach ($users as $user) {
                    $tierId = $this->tierResolver->resolveTierId($user);
                    if ($tierId === $needle) {
                        $autoIds[] = (int) $user->id;
                        if (count($autoIds) >= $remainingCap) {
                            $truncated = true;
                            return false;
                        }
                    }
                }
                return true;
            });

        return $autoIds;
    }

    /**
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
     * LIKE com wildcards do input neutralizados antes de virar pattern.
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
