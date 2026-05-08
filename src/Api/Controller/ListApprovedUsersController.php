<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;

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

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db
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

        $tiers = TierConfig::fromSettings($this->settings);

        // Union of every group ID that auto-grants any tier.
        $autoVerifiedGroupIds = [];
        foreach ($tiers as $t) {
            foreach ($t['autoGroups'] as $gid) $autoVerifiedGroupIds[$gid] = true;
        }
        $autoVerifiedGroupIds = array_keys($autoVerifiedGroupIds);

        $query = User::query()->where(function (Builder $w) use ($autoVerifiedGroupIds) {
            $w->where('is_verified', true);

            if (! empty($autoVerifiedGroupIds)) {
                $w->orWhereExists(function ($sub) use ($autoVerifiedGroupIds) {
                    $sub->from('group_user')
                        ->whereColumn('group_user.user_id', 'users.id')
                        ->whereIn('group_user.group_id', $autoVerifiedGroupIds);
                });
            }
        });

        if ($q !== '') {
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
        // (because auto-tier comes from groups, not a direct DB column).
        // To keep pagination meaningful when filtering, we fetch all matches
        // for that tier first then slice. The `q` + tier filter scope is
        // typically small enough that this isn't a perf concern.
        if ($tierFilter !== '') {
            $allMatching = $query
                ->orderBy('is_verified', 'desc')
                ->orderBy('verified_at', 'desc')
                ->orderBy('username', 'asc')
                ->get();
            $allMatching->load('groups');

            $needle = strtolower($tierFilter);
            $filtered = $allMatching->filter(function (User $user) use ($tiers, $needle) {
                $tierId = $this->resolveTierId($user, $tiers);
                return $tierId !== null && $tierId === $needle;
            })->values();

            $total = $filtered->count();
            $users = $filtered->slice($offset, $limit)->values();
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

        $data = $users->map(function (User $user) use ($approvedRequests, $handlers, $autoVerifiedGroupSet, $tiers) {
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
                'verifiedTier'       => $this->resolveTierId($user, $tiers),
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
     * Same logic as `UserResourceFields::resolveTierId` — kept colocated so
     * we don't need to instantiate that class here. Returns the tier id or
     * null when the user has no tier and no legacy verified flag.
     *
     * @param array<int, array> $tiers
     */
    private function resolveTierId(User $user, array $tiers): ?string
    {
        if (empty($tiers)) return null;

        $manual = is_string($user->verified_tier) && $user->verified_tier !== ''
            ? strtolower($user->verified_tier)
            : null;

        if ($manual !== null) {
            $tier = TierConfig::findById($tiers, $manual);
            if ($tier) return $tier['id'];
            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        $userGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all();
        $autoTier = TierConfig::autoTierFor($tiers, $userGroupIds);
        if ($autoTier) return $autoTier['id'];

        if ((bool) $user->is_verified) {
            $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
            return $fallback['id'];
        }

        return null;
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
     * NB: we go through the injected `ConnectionInterface` rather than the
     * `Schema` facade — Flarum doesn't bootstrap Laravel's Facade root, so
     * `Schema::hasColumn(...)` blows up with "A facade root has not been set".
     *
     * @return string[]
     */
    private function searchableColumns(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $columns = ['username', 'email'];
        $schema = $this->db->getSchemaBuilder();
        foreach (['nickname', 'display_name'] as $optional) {
            if ($schema->hasColumn('users', $optional)) {
                $columns[] = $optional;
            }
        }
        return $cached = $columns;
    }
}
