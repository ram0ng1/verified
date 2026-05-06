<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Paginated, searchable list of every "approved" user — includes both users
 * verified manually (column `is_verified=1`) and users auto-verified through
 * group membership (the `verified.autoVerified` permission). The frontend
 * uses this to populate the admin "Approved" tab.
 *
 * Inputs (query string):
 *   - q       : string — username/email search fragment.
 *   - offset  : int    — pagination offset, defaults to 0.
 *   - limit   : int    — page size, defaults to 15, capped at 50.
 *
 * Output (JSON):
 *   - data : list of users, each with { source, request?, groups? }
 *   - meta : { total, limit, offset }
 */
class ListApprovedUsersController implements RequestHandlerInterface
{
    public const DEFAULT_LIMIT = 15;
    public const MAX_LIMIT = 50;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $params = $request->getQueryParams();
        $q      = isset($params['q']) ? trim((string) $params['q']) : '';
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit  = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) $limit = self::DEFAULT_LIMIT;
        if ($limit > self::MAX_LIMIT) $limit = self::MAX_LIMIT;

        // Group IDs whose members get the badge automatically.
        $autoVerifiedGroupIds = Permission::query()
            ->where('permission', 'verified.autoVerified')
            ->pluck('group_id')
            ->all();

        $query = User::query()->where(function (Builder $w) use ($autoVerifiedGroupIds) {
            $w->where('is_verified', true);

            if (! empty($autoVerifiedGroupIds)) {
                // No `select(...)` needed: EXISTS only cares about row presence,
                // not which columns come back. Avoiding `DB::raw(1)` here keeps
                // the controller free of the Laravel facade root, which Flarum
                // doesn't bootstrap.
                $w->orWhereExists(function ($sub) use ($autoVerifiedGroupIds) {
                    $sub->from('group_user')
                        ->whereColumn('group_user.user_id', 'users.id')
                        ->whereIn('group_user.group_id', $autoVerifiedGroupIds);
                });
            }
        });

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(function (Builder $w) use ($like) {
                $w->where('username', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('display_name', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $users = $query
            ->orderBy('is_verified', 'desc')
            ->orderBy('verified_at', 'desc')
            ->orderBy('username', 'asc')
            ->skip($offset)
            ->take($limit)
            ->get();

        if ($users->isEmpty()) {
            return new JsonResponse([
                'data' => [],
                'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
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

        // Eager-load groups for users that are auto-verified, so the frontend
        // can render "Verified through group: <name>" without N+1 queries.
        $users->load('groups');

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
                'id'           => (int) $user->id,
                'username'     => (string) $user->username,
                'displayName'  => $user->display_name ?: (string) $user->username,
                'avatarUrl'    => $user->avatar_url,
                'source'       => $source,
                'isVerified'   => (bool) $user->is_verified,
                'verifiedAt'   => $user->verified_at ? $user->verified_at->toRfc3339String() : null,
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
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ]);
    }
}
