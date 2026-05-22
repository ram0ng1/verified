<?php

namespace Ramon\Verified\Api;

use Flarum\Extension\ExtensionManager;
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
 * O caso difícil é o filtro por tier — conjunto virtual sem query SQL única
 * paginável, porque o tier efetivo do ramo auto só se resolve em PHP. A
 * página é montada sem materializar o conjunto inteiro:
 *   - Manual: o tier mora numa coluna (`verified_tier`), então conta por
 *     `COUNT(*)` e busca só a fatia da página por `LIMIT/OFFSET` — nenhum ID
 *     materializado.
 *   - Auto: o `chunkById` percorre só os candidatos (membros dos `autoGroups`
 *     do tier filtrado), conta os que resolvem para o tier-alvo e guarda
 *     apenas os IDs dentro da janela da página — no máximo `limit` IDs, não
 *     o conjunto. As contagens param em `TIER_FILTER_TOTAL_CAP`.
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
        protected TierResolver $tierResolver,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * Colunas pesquisáveis da tabela `users`. `username` e `email` são core;
     * `nickname` só entra quando a extensão `flarum/nicknames` está ativa,
     * detectada pelo `ExtensionManager` em vez de introspecção de schema
     * (`getSchemaBuilder()->hasColumn()` dispara um SELECT em INFORMATION_SCHEMA
     * a cada cache frio). `display_name` não é coluna no Flarum 2 — é um driver
     * de nome computado — então nunca foi candidata a LIKE.
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
     * Listagem filtrada por tier. O conjunto é virtual (manual unido a auto)
     * e o tier do ramo auto só se resolve em PHP, então não há query SQL
     * única paginável. A página é montada sem materializar o conjunto: o
     * ramo manual conta por `COUNT(*)` e busca a fatia por `LIMIT/OFFSET`; o
     * ramo auto percorre os candidatos uma vez, conta os que casam o tier e
     * guarda só os IDs da janela da página.
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

        $cap = self::TIER_FILTER_TOTAL_CAP;
        $offset = $criteria->offset;
        $limit  = $criteria->limit;

        $manualQuery = $this->manualTierQuery($criteria, $needle, $isDefaultTier, $blueIsConfigured);
        $manualTotal = (clone $manualQuery)->count();
        $manualTruncated = $manualTotal >= $cap;
        $manualCount = min($manualTotal, $cap);

        $autoCount = 0;
        $autoTruncated = false;
        $autoPageIds = [];

        if (! $manualTruncated && ! empty($targetTierGroupIds)) {
            // Janela da página em coordenadas locais do ramo auto, que segue
            // o manual na união: posições da página que caem após o manual.
            $autoWindowStart  = max(0, $offset - $manualCount);
            $autoWindowLength = max(0, ($offset + $limit) - max($offset, $manualCount));

            $auto = $this->collectAutoTierPage(
                $criteria,
                $targetTierGroupIds,
                $adminAllowed,
                $needle,
                $cap - $manualCount,
                $autoWindowStart,
                $autoWindowLength
            );
            $autoCount     = $auto['count'];
            $autoTruncated = $auto['truncated'];
            $autoPageIds   = $auto['pageIds'];
        }

        $total     = $manualCount + $autoCount;
        $truncated = $manualTruncated || $autoTruncated;

        $ordered = [];

        // Fatia manual da página — SQL puro, sem materializar IDs.
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
                $ordered[] = $row;
            }
        }

        // Fatia auto da página — só os IDs já coletados na janela.
        if (! empty($autoPageIds)) {
            $autoFetched = User::query()
                ->with(['groups', 'verification'])
                ->whereIn('id', $autoPageIds)
                ->get()
                ->keyBy('id');
            foreach ($autoPageIds as $id) {
                $row = $autoFetched->get($id);
                if ($row !== null) {
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
     * Builder do ramo manual do filtro de tier: join na tabela companheira,
     * `is_verified=1` e casamento do tier (`verified_tier`, ou NULL quando o
     * tier-alvo é o default azul configurado). Sem ordenação nem limite — o
     * caller decide se conta (`COUNT`) ou pagina (`LIMIT/OFFSET`).
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
     * Percorre os candidatos do ramo auto (membros dos `autoGroups` do tier
     * filtrado), confirma o tier efetivo em PHP via `resolveTierId` e devolve
     * a contagem total mais os IDs que caem na janela `[windowStart, +len)`.
     * Guarda no máximo `windowLength` IDs — a contagem segue até `$cap`, mas o
     * array de página não cresce com o conjunto. `truncated=true` quando o
     * walk atinge o cap antes de esgotar os candidatos.
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
