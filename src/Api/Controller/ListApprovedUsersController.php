<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;
use Ramon\Verified\TierResolver;

/**
 * Paginated, searchable list of every "approved" user — includes both users
 * verified manually (column `is_verified=1`) and users auto-verified through
 * tier `autoGroups` membership. The frontend uses this to populate the admin
 * "Approved" tab.
 *
 * Inputs (query string):
 *   - q       : string — username/email search fragment.
 *   - tier    : string — optional tier id filter (only return users on that tier).
 *   - offset  : int    — pagination offset, defaults to 0.
 *   - limit   : int    — page size, defaults to 15, capped at 50.
 *
 * Output (JSON):
 *   - data : list of users, each with { source, verifiedTier, request?, autoVerifiedGroups }
 *   - meta : { total, limit, offset, tiers }
 */
class ListApprovedUsersController implements RequestHandlerInterface
{
    public const DEFAULT_LIMIT = 15;
    public const MAX_LIMIT = 50;

    /**
     * Batch size for the tier-filter scan. Big enough that small/medium
     * forums finish in one round-trip; small enough that a tier with
     * tens of thousands of users doesn't try to materialise them all
     * into memory at once (audit T1).
     */
    private const TIER_FILTER_CHUNK = 200;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tierResolver
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $params = $request->getQueryParams();
        $q          = isset($params['q']) ? trim((string) $params['q']) : '';
        $tierFilter = isset($params['tier']) ? trim((string) $params['tier']) : '';
        $offset     = max(0, (int) ($params['offset'] ?? 0));
        $limit      = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) $limit = self::DEFAULT_LIMIT;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;

        $tiers = $this->tierResolver->tiers();

        // Union of every group ID that auto-grants any tier.
        $autoVerifiedGroupIds = [];
        foreach ($tiers as $t) {
            foreach ($t['autoGroups'] as $gid) $autoVerifiedGroupIds[$gid] = true;
        }
        $autoVerifiedGroupIds = array_keys($autoVerifiedGroupIds);

        // Admins are exempt from auto-grant through implicit membership groups
        // (e.g., "Members") — only an explicit Admin-group tier auto-verifies
        // them. Mirror the rule TierConfig::autoTierFor enforces in PHP, but
        // applied at the SQL layer so paginated counts stay accurate.
        $adminAllowed = in_array(Group::ADMINISTRATOR_ID, $autoVerifiedGroupIds, true);

        $query = User::query()->where(function (Builder $w) use ($autoVerifiedGroupIds, $adminAllowed) {
            $w->where('is_verified', true);

            if (! empty($autoVerifiedGroupIds)) {
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
            }
        });

        if ($q !== '') {
            // LIKE wildcard escape — neutralise %, _ and \ from the user's
            // query so a typed `_` doesn't match every single character on
            // the column (CLAUDE.md §10 / "LIKE — escape user wildcards").
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

        // Tier filter is applied in PHP after we resolve each user's tier
        // (auto-tier comes from groups, not a single column, so SQL alone
        // can't express the manual-beats-auto precedence). To keep memory
        // bounded on big forums we stream candidate rows via chunkById
        // instead of buffering the entire matching set in one collection
        // (audit T1) — at most TIER_FILTER_CHUNK User models live in memory
        // at once.
        if ($tierFilter !== '') {
            $needle = strtolower($tierFilter);
            $matchingIds = [];

            $query
                ->orderBy('is_verified', 'desc')
                ->orderBy('verified_at', 'desc')
                ->orderBy('username', 'asc')
                ->orderBy('id', 'asc')
                ->chunkById(self::TIER_FILTER_CHUNK, function ($users) use ($needle, &$matchingIds) {
                    $users->load('groups');
                    foreach ($users as $user) {
                        $tierId = $this->tierResolver->resolveTierId($user);
                        if ($tierId !== null && $tierId === $needle) {
                            $matchingIds[] = (int) $user->id;
                        }
                    }
                });

            $total   = count($matchingIds);
            $pageIds = array_slice($matchingIds, $offset, $limit);

            if (empty($pageIds)) {
                $users = collect();
            } else {
                // Re-fetch only the page's worth of users with groups loaded.
                // Re-order in PHP to match $pageIds so pagination is stable —
                // avoids portability traps with MySQL's `FIELD()`, which
                // doesn't exist on every backend Flarum may run against.
                $fetched = User::query()->whereIn('id', $pageIds)->get()->keyBy('id');
                $users = collect($pageIds)
                    ->map(fn (int $id) => $fetched->get($id))
                    ->filter()
                    ->values();
                $users->load('groups');
            }
        } else {
            $total = (clone $query)->count();
            $users = $query
                ->orderBy('is_verified', 'desc')
                ->orderBy('verified_at', 'desc')
                ->orderBy('username', 'asc')
                ->skip($offset)
                ->take($limit)
                ->get();
            $users->load('groups');
        }

        if ($users->isEmpty()) {
            return new JsonResponse([
                'data' => [],
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'tiers' => $this->tiersMeta($tiers),
                ],
            ]);
        }

        $userIds = $users->pluck('id')->all();

        // Latest APPROVED request per user (one query, grouped client-side).
        $approvedRequests = VerificationRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', VerificationRequest::STATUS_APPROVED)
            ->orderBy('handled_at', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('user_id');

        // Pre-load handler users for the approved requests (one query).
        $handlerIds = [];
        foreach ($approvedRequests as $list) {
            $first = $list->first();
            if ($first && $first->handled_by) $handlerIds[] = (int) $first->handled_by;
        }
        $handlers = empty($handlerIds)
            ? collect()
            : User::query()->whereIn('id', array_unique($handlerIds))->get()->keyBy('id');

        $autoVerifiedGroupSet = array_flip($autoVerifiedGroupIds);

        $data = $users->map(function (User $user) use ($approvedRequests, $handlers, $autoVerifiedGroupSet) {
            $latestRequest = $approvedRequests->has($user->id)
                ? $approvedRequests[$user->id]->first()
                : null;

            // Determine source. A user can be verified manually (column),
            // automatically (group), or both. We prefer "manual" when both
            // apply — the request row is more informative.
            $source = (bool) $user->is_verified ? 'manual' : 'group';

            // Groups that grant the auto-verified badge for this user.
            $autoGroups = $user->groups
                ->filter(fn (Group $g) => isset($autoVerifiedGroupSet[$g->id]))
                ->map(fn (Group $g) => [
                    'id'    => (int) $g->id,
                    'name'  => (string) $g->name_singular,
                    'color' => $g->color,
                    'icon'  => $g->icon,
                ])
                ->values()
                ->all();

            $row = [
                'id'                 => (int) $user->id,
                'username'           => (string) $user->username,
                'displayName'        => (string) ($user->display_name ?: $user->nickname ?: $user->username),
                'avatarUrl'          => $user->avatar_url,
                'source'             => $source,
                'isVerified'         => (bool) $user->is_verified,
                'verifiedAt'         => $user->verified_at ? $user->verified_at->toRfc3339String() : null,
                'verifiedTier'       => $this->tierResolver->resolveTierId($user),
                'autoVerifiedGroups' => $autoGroups,
            ];

            if ($latestRequest) {
                $handler = $latestRequest->handled_by ? $handlers->get((int) $latestRequest->handled_by) : null;

                $row['request'] = [
                    'id'           => (int) $latestRequest->id,
                    'reason'       => $latestRequest->reason,
                    'documentType' => $latestRequest->document_type,
                    'documentPath' => $latestRequest->document_path,
                    'adminNote'    => $latestRequest->admin_note,
                    'createdAt'    => $latestRequest->created_at ? $latestRequest->created_at->toRfc3339String() : null,
                    'handledAt'    => $latestRequest->handled_at ? $latestRequest->handled_at->toRfc3339String() : null,
                    'handler'      => $handler ? [
                        'id'          => (int) $handler->id,
                        'username'    => (string) $handler->username,
                        'displayName' => $handler->display_name ?: (string) $handler->username,
                    ] : null,
                ];
            }

            return $row;
        });

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'tiers' => $this->tiersMeta($tiers),
            ],
        ]);
    }

    /**
     * Tier metadata sent down with every page so the frontend can render
     * tab/badge labels without re-reading settings.
     *
     * @param array<int, array> $tiers
     * @return array<int, array{id:string,label:string,color:string}>
     */
    private function tiersMeta(array $tiers): array
    {
        return array_map(fn ($t) => [
            'id'    => $t['id'],
            'label' => $t['label'],
            'color' => $t['color'],
        ], $tiers);
    }

    /**
     * Resolve which columns on `users` to LIKE-search against. `username` and
     * `email` are part of stock Flarum and always present. `nickname` and
     * `display_name` only exist when the corresponding extension is installed
     * (flarum/nicknames, custom forks); we probe at runtime so an admin search
     * doesn't 500 on forums that lack them.
     *
     * NB: we resolve the schema builder via the Eloquent model rather than
     * injecting `ConnectionInterface` directly — keeps the controller free
     * of raw Laravel DB plumbing (audit C-conventions). Flarum doesn't
     * bootstrap Laravel's Facade root, so `Schema::hasColumn(...)` blows
     * up with "A facade root has not been set"; reaching through the model
     * avoids that footgun.
     *
     * @return string[]
     */
    private function searchableColumns(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $columns = ['username', 'email'];
        $schema  = (new User())->getConnection()->getSchemaBuilder();
        foreach (['nickname', 'display_name'] as $optional) {
            if ($schema->hasColumn('users', $optional)) {
                $columns[] = $optional;
            }
        }
        return $cached = $columns;
    }
}
