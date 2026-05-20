<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Api\ApprovedUserCriteria;
use Ramon\Verified\Api\ApprovedUserQuery;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Listagem paginada e pesquisável dos usuários "aprovados" — manuais
 * (`is_verified=1`) e auto-verificados via tier `autoGroups`. Aba "Approved"
 * do admin. Toda a complexidade de query/paginação fica em `ApprovedUserQuery`;
 * este handler só recebe critérios, resolve o page, monta o payload.
 *
 * Estas linhas são um agregado virtual (união de dois conjuntos), sem model
 * próprio — não cabem num `AbstractDatabaseResource`. O payload, porém, segue
 * o envelope JSON:API: `data` é uma lista de objetos `{type, id, attributes}`
 * e `meta` carrega total/paginação/tiers.
 */
class ListApprovedUsersController implements RequestHandlerInterface
{
    public const DEFAULT_LIMIT = 15;
    public const MAX_LIMIT = 50;
    public const RESOURCE_TYPE = 'verified-approved-user';

    public function __construct(
        protected ApprovedUserQuery $query,
        protected TierResolver $tierResolver,
        protected VerifiedStatus $verifiedStatus
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $criteria = ApprovedUserCriteria::fromQuery(
            $request->getQueryParams(),
            self::DEFAULT_LIMIT,
            self::MAX_LIMIT
        );

        $page = $this->query->page($criteria);

        $tiers = $this->tierResolver->tiers();

        if ($page['users']->isEmpty()) {
            return new JsonResponse([
                'data' => [],
                'meta' => $this->buildMeta($criteria, $page['total'], $page['truncated'], $tiers),
            ]);
        }

        return new JsonResponse([
            'data' => $this->buildData($page['users']),
            'meta' => $this->buildMeta($criteria, $page['total'], $page['truncated'], $tiers),
        ]);
    }

    /**
     * Monta a lista `data` como objetos-recurso JSON:API. O `id` vai no
     * objeto-recurso (string, conforme a spec); os demais campos em
     * `attributes`.
     *
     * @param \Illuminate\Support\Collection<int, User> $users
     */
    private function buildData($users): array
    {
        $userIds = $users->pluck('id')->all();

        $latestApprovedIds = VerificationRequest::query()
            ->selectRaw('MAX(id) AS id')
            ->whereIn('user_id', $userIds)
            ->where('status', VerificationRequest::STATUS_APPROVED)
            ->groupBy('user_id')
            ->pluck('id')
            ->all();

        $approvedRequests = empty($latestApprovedIds)
            ? collect()
            : VerificationRequest::query()
                ->whereIn('id', $latestApprovedIds)
                ->get()
                ->groupBy('user_id');

        $handlerIds = [];
        foreach ($approvedRequests as $list) {
            $first = $list->first();
            if ($first && $first->handled_by) $handlerIds[] = (int) $first->handled_by;
        }
        $handlers = empty($handlerIds)
            ? collect()
            : User::query()->whereIn('id', array_unique($handlerIds))->get()->keyBy('id');

        $autoVerifiedGroupSet = array_flip($this->query->autoVerifiedGroupIds());

        return $users->map(fn (User $user) => [
            'type'       => self::RESOURCE_TYPE,
            'id'         => (string) $user->id,
            'attributes' => $this->buildAttributes(
                $user,
                $approvedRequests,
                $handlers,
                $autoVerifiedGroupSet
            ),
        ])->all();
    }

    /**
     * @param array<int, true> $autoVerifiedGroupSet
     */
    private function buildAttributes(User $user, $approvedRequests, $handlers, array $autoVerifiedGroupSet): array
    {
        $latestRequest = $approvedRequests->has($user->id)
            ? $approvedRequests[$user->id]->first()
            : null;

        $isManual = $this->verifiedStatus->isVerified($user);
        $source = $isManual ? 'manual' : 'group';
        $verifiedAt = $this->verifiedStatus->verifiedAt($user);

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
            'username'           => (string) $user->username,
            'displayName'        => (string) ($user->display_name ?: $user->nickname ?: $user->username),
            'avatarUrl'          => $user->avatar_url,
            'source'             => $source,
            'isVerified'         => $isManual,
            'verifiedAt'         => $verifiedAt ? $verifiedAt->toRfc3339String() : null,
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
    }

    /**
     * @param array<int, array> $tiers
     */
    private function buildMeta(ApprovedUserCriteria $criteria, int $total, bool $truncated, array $tiers): array
    {
        return [
            'total'     => $total,
            'limit'     => $criteria->limit,
            'offset'    => $criteria->offset,
            'truncated' => $truncated,
            'tiers'     => array_map(fn ($t) => [
                'id'    => $t['id'],
                'label' => $t['label'],
                'color' => $t['color'],
            ], $tiers),
        ];
    }
}
