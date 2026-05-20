<?php

namespace Ramon\Verified\Api;

use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Verified\TierConfig;
use Ramon\Verified\TierResolver;

/**
 * Constrói as queries de listagem dos "verificados aprovados" para o admin.
 * Une o conjunto manual (`is_verified=1`) com o conjunto auto-verificado
 * (membros de `autoGroups` de algum tier configurado).
 *
 * O caso difícil é o filtro por tier. Os dois ramos são limitados por
 * `TIER_FILTER_TOTAL_CAP` para nunca materializar mais que esse número de
 * IDs em memória:
 *   - Manual: o tier mora numa coluna (`verified_tier`), então o filtro é
 *     SQL puro — basta um `LIMIT` no cap para o pluck não crescer com a
 *     quantidade de verificados manuais de um tier.
 *   - Auto: o tier efetivo depende da ordem de prioridade dos `autoGroups`,
 *     então a confirmação final roda em PHP — mas a query SQL já restringe
 *     os candidatos aos membros dos `autoGroups` do tier filtrado, então o
 *     `chunkById` percorre só quem PODE ter o tier, não todo o conjunto
 *     auto-verificado; para no momento em que houver IDs para o cap restante.
 * Quando qualquer ramo atinge o cap, `truncated=true` sinaliza ao admin que
 * a busca precisa ser refinada.
 */
class ApprovedUserQuery
{
    public const TIER_FILTER_CHUNK     = 200;
    public const TIER_FILTER_TOTAL_CAP = 5000;

    /**
     * Cache de instância. Em processos longos (Octane, queue workers) um
     * `static` no método persistiria entre requests/jobs e poderia ficar
     * obsoleto após uma migração rodada in-process. O resolver é instanciado
     * sob demanda por request, então o cache morre junto com a instância —
     * exatamente o escopo desejado.
     *
     * @var string[]|null
     */
    private ?array $searchableColumnsCache = null;

    public function __construct(
        protected TierResolver $tierResolver
    ) {
    }

    /**
     * Colunas pesquisáveis da tabela `users`. `nickname` (extensão
     * `flarum/nicknames`) e `display_name` (Flarum 2 core) só entram quando
     * realmente existem. O schema builder vem da conexão do próprio model
     * `User` — sem injetar um `ConnectionResolverInterface` de baixo nível
     * só para introspecção de schema (§39.3: Eloquent primeiro).
     *
     * @return string[]
     */
    public function searchableColumns(): array
    {
        if ($this->searchableColumnsCache !== null) {
            return $this->searchableColumnsCache;
        }

        $columns = ['username', 'email'];
        $schema = User::query()->getConnection()->getSchemaBuilder();
        foreach (['nickname', 'display_name'] as $optional) {
            if ($schema->hasColumn('users', $optional)) {
                $columns[] = $optional;
            }
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
     * Ordena por presença da linha companion (manuais primeiro, depois
     * auto-tier), data de verificação e username. Usa `IS NULL ASC` em
     * vez de `IS NOT NULL DESC` por portabilidade (§39.2): MySQL aceita
     * predicado booleano em ORDER BY como 0/1, PostgreSQL não — `IS NULL
     * ASC` produz a mesma ordem nos dois dialetos e em SQLite.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Flarum\User\User>
     */
    private function orderByPresenceAndPaginate($query, ApprovedUserCriteria $criteria)
    {
        return $query
            ->leftJoin('user_verification', 'user_verification.user_id', '=', 'users.id')
            ->select('users.*')
            ->orderByRaw('user_verification.user_id IS NULL ASC')
            ->orderByDesc('user_verification.verified_at')
            ->orderBy('users.username', 'asc')
            ->skip($criteria->offset)
            ->take($criteria->limit)
            ->get();
    }

    /**
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

        $cap = self::TIER_FILTER_TOTAL_CAP;

        $manualIds = $this->collectManualTierIds($criteria, $needle, $isDefaultTier, $blueIsConfigured, $cap);
        $manualCount = count($manualIds);

        $truncated = $manualCount >= $cap;
        $autoIds = [];

        if (! $truncated && ! empty($targetTierGroupIds)) {
            $remainingCap = $cap - $manualCount;
            $autoIds = $this->collectAutoTierIds(
                $criteria,
                $targetTierGroupIds,
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
        $ordered = [];
        foreach ($pageIds as $id) {
            $row = $indexed->get($id);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }
        $users = $fetched->make($ordered);

        return [
            'users'     => $users,
            'total'     => $total,
            'truncated' => $truncated,
        ];
    }

    /**
     * Caminho manual com cap. O filtro é SQL puro (`verified_tier` é coluna),
     * então só precisamos limitar o pluck a `$cap` linhas — sem o `LIMIT`,
     * um tier com dezenas de milhares de verificados manuais materializaria
     * todos os IDs em memória. O caller marca `truncated` quando a contagem
     * devolvida atinge o cap.
     *
     * @return int[]
     */
    private function collectManualTierIds(
        ApprovedUserCriteria $criteria,
        string $needle,
        bool $isDefaultTier,
        bool $blueIsConfigured,
        int $cap
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
            ->limit($cap)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Caminho auto-only com cap. O `whereExists` restringe os candidatos aos
     * membros dos `autoGroups` do tier filtrado — só quem está num desses
     * grupos PODE resolver para o tier-alvo, então o scan PHP percorre um
     * conjunto bem menor que "todos os auto-verificados". A confirmação final
     * (`resolveTierId`) ainda roda em PHP porque a precedência entre tiers e
     * a imunidade de admin não cabem num único predicado SQL. Para assim que
     * a contagem coletada chega ao limite — truncated=true sinaliza ao admin.
     *
     * @param int[] $targetTierGroupIds
     * @return int[]
     */
    private function collectAutoTierIds(
        ApprovedUserCriteria $criteria,
        array $targetTierGroupIds,
        bool $adminAllowed,
        string $needle,
        int $remainingCap,
        bool &$truncated
    ): array {
        if (empty($targetTierGroupIds)) {
            return [];
        }

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
     * Conjunto "aprovado" sem filtro de tier: manuais (`is_verified=1`) unidos
     * aos auto-verificados por grupo. O ramo auto exclui usuários com tombstone
     * de opt-out (`user_verification.auto_revoked_at` set) — eles continuam no
     * `autoGroup` mas pediram para sair, e `TierResolver::resolveTierId` já os
     * trata como não-verificados; sem este filtro a listagem do admin os
     * mostraria como aprovados.
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
