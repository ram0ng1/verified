<?php

namespace Ramon\Verified\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\Service\Verification\VerificationRequestService;

/**
 * @extends AbstractDatabaseResource<VerificationRequest>
 */
class VerificationRequestResource extends AbstractDatabaseResource
{
    public function __construct(
        protected VerificationRequestService $service
    ) {
    }

    public function type(): string
    {
        return 'verification-requests';
    }

    public function model(): string
    {
        return VerificationRequest::class;
    }

    /**
     * Restringe a query por ator (não-admins veem só os próprios pedidos) e
     * aplica o filtro custom `?byStatus=pending,rejected`. Dois pontos de
     * cuidado do Flarum 2 / json-api-server: (1) `AbstractDatabaseResource::filters()`
     * é `final` e lança "use a model searcher" sempre que o JSON:API server
     * invoca `applyFilters` com qualquer `filter[]` no request; (2)
     * `JsonApi::validateQueryParameters` rejeita query params custom cujo
     * nome seja só `[a-z]` minúsculo (regex `/[^a-z]/`) — `status` falha;
     * `byStatus` (camelCase) passa. PSR-7 entrega o valor intacto aqui.
     */
    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $actor = $context->getActor();

        if (! $actor->isAdmin()) {
            $query->where('user_id', $actor->id);
        }

        $params = $context->request->getQueryParams();
        $rawStatus = $params['byStatus'] ?? null;

        if ($rawStatus !== null) {
            $statuses = is_array($rawStatus)
                ? $rawStatus
                : array_map('trim', explode(',', (string) $rawStatus));

            $allowed = [
                VerificationRequest::STATUS_PENDING,
                VerificationRequest::STATUS_APPROVED,
                VerificationRequest::STATUS_REJECTED,
            ];
            $statuses = array_values(array_intersect(array_map('strval', $statuses), $allowed));

            if (! empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->paginate(15, 50)
                ->defaultInclude(['user', 'handler']),

            Endpoint\Show::make()
                ->authenticated()
                ->can('view')
                ->defaultInclude(['user', 'handler']),

            Endpoint\Create::make()
                ->authenticated()
                ->action(fn (Context $context) => $this->service->create($context)),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('verified.approve')
                ->route('POST', '/{id}/approve')
                ->admin()
                ->action(fn (Context $context) => $this->service->approve($context)),

            Endpoint\Endpoint::make('verified.reject')
                ->route('POST', '/{id}/reject')
                ->admin()
                ->action(fn (Context $context) => $this->service->reject($context)),

            Endpoint\Endpoint::make('verified.revoke')
                ->route('POST', '/{id}/revoke')
                ->admin()
                ->action(fn (Context $context) => $this->service->revoke($context)),
        ];
    }

    /**
     * Nenhum campo é writable pela API — os endpoints customizados leem só
     * `reason`/`documentType`/`documentPath` do body; o resto é server-set.
     * `documentPath` fica visível apenas para admin.
     */
    public function fields(): array
    {
        $serverOnly = fn () => false;

        return [
            Schema\Str::make('status')->writable($serverOnly),
            Schema\Str::make('documentType')
                ->property('document_type')
                ->nullable(),
            Schema\Str::make('documentPath')
                ->property('document_path')
                ->nullable()
                ->visible(fn (VerificationRequest $request, Context $context) =>
                    $context->getActor()->isAdmin()),
            Schema\Str::make('reason')->nullable(),
            Schema\Str::make('adminNote')
                ->property('admin_note')
                ->nullable()
                ->writable($serverOnly)
                ->visible(fn (VerificationRequest $request, Context $context) =>
                    $context->getActor()->isAdmin()
                    || (int) $context->getActor()->id === (int) $request->user_id),
            Schema\DateTime::make('createdAt')
                ->property('created_at')
                ->writable($serverOnly),
            Schema\DateTime::make('updatedAt')
                ->property('updated_at')
                ->writable($serverOnly),
            Schema\DateTime::make('handledAt')
                ->property('handled_at')
                ->nullable()
                ->writable($serverOnly),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('handler')
                ->type('users')
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt')->column('created_at'),
            SortColumn::make('updatedAt')->column('updated_at'),
            SortColumn::make('status'),
        ];
    }
}
