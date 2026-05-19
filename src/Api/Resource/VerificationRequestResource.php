<?php

namespace Ramon\Verified\Api\Resource;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * @extends AbstractDatabaseResource<VerificationRequest>
 */
class VerificationRequestResource extends AbstractDatabaseResource
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected Dispatcher $events,
        protected DocumentRetention $retention,
        protected DocumentPathResolver $pathResolver,
        protected FilesystemFactory $filesystem,
        protected VerifiedStatus $verifiedStatus,
        protected TierResolver $tiers
    ) {
    }

    /**
     * Roda o callback dentro de uma transação do connection do Flarum,
     * resolvido pelo próprio model — evita injetar `ConnectionInterface`
     * direto no resource só para acessar `->transaction()`.
     */
    private function transaction(callable $cb): mixed
    {
        return VerificationRequest::query()->getConnection()->transaction($cb);
    }

    public function type(): string
    {
        return 'verification-requests';
    }

    public function model(): string
    {
        return VerificationRequest::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $actor = $context->getActor();

        if (! $actor->isAdmin()) {
            $query->where('user_id', $actor->id);
        }

        // `?byStatus=pending,rejected` (lista CSV ou repetição) — em vez de
        // `filter[status]`. Dois pontos de cuidado do Flarum 2 / json-api-server:
        //  1. `AbstractDatabaseResource::filters()` é `final` e lança "use a
        //     model searcher" sempre que o JSON:API server invoca
        //     `applyFilters` com qualquer `filter[]` no request.
        //  2. `JsonApi::validateQueryParameters()` REJEITA qualquer query
        //     param custom cujo nome seja só `[a-z]` minúsculo
        //     (`status` falha; `byStatus` passa porque tem letra maiúscula —
        //     a regex usada é `/[^a-z]/`).
        // A combinação obriga a usar um nome com char fora de `a-z` para
        // params custom. PSR-7 entrega o valor intacto aqui no scope.
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
                ->action(fn (Context $context) => $this->createRequest($context)),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('verified.approve')
                ->route('POST', '/{id}/approve')
                ->admin()
                ->action(fn (Context $context) => $this->approveRequest($context)),

            Endpoint\Endpoint::make('verified.reject')
                ->route('POST', '/{id}/reject')
                ->admin()
                ->action(fn (Context $context) => $this->rejectRequest($context)),

            Endpoint\Endpoint::make('verified.revoke')
                ->route('POST', '/{id}/revoke')
                ->admin()
                ->action(fn (Context $context) => $this->revokeRequest($context)),
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

    private function createRequest(Context $context): VerificationRequest
    {
        $actor = $context->getActor();
        $actor->assertRegistered();
        $actor->assertCan('verified.request');

        if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.requests_closed'),
            ]);
        }

        if ($this->verifiedStatus->isVerified($actor)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_verified'),
            ]);
        }

        $existing = VerificationRequest::query()
            ->where('user_id', $actor->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_pending'),
            ]);
        }

        $body = $context->body();
        $attributes = (array) ($body['data']['attributes'] ?? []);

        $reason       = isset($attributes['reason']) ? trim((string) $attributes['reason']) : null;
        $documentType = isset($attributes['documentType']) ? trim((string) $attributes['documentType']) : null;
        $documentPath = isset($attributes['documentPath']) ? trim((string) $attributes['documentPath']) : null;

        if ($reason !== null && mb_strlen($reason) > 1000) {
            $reason = mb_substr($reason, 0, 1000);
        }
        if ($documentType !== null && mb_strlen($documentType) > 32) {
            $documentType = mb_substr($documentType, 0, 32);
        }

        if ($documentType !== null && $documentType !== '') {
            $documentType = $this->resolveDocumentType($documentType);
        }

        $documentRequired = (bool) $this->settings->get('ramon-verified.require_document');
        $documentIsLive = $documentPath !== null
            && $documentPath !== ''
            && $this->pathResolver->isOwnedDocumentToken($documentPath, (int) $actor->id)
            && $this->documentFileExists($actor, $documentPath);

        if ($documentRequired && ! $documentIsLive) {
            throw new ValidationException([
                'documentPath' => $this->translator->trans('ramon-verified.api.document_required'),
            ]);
        }
        if (! $documentIsLive) {
            $documentPath = null;
        }

        $request = new VerificationRequest();
        $request->user_id       = (int) $actor->id;
        $request->status        = VerificationRequest::STATUS_PENDING;
        $request->reason        = $reason ?: null;
        $request->document_type = $documentType ?: null;
        $request->document_path = $documentPath ?: null;
        $request->created_at    = Carbon::now();
        $request->updated_at    = Carbon::now();
        $request->save();

        return $request;
    }

    /**
     * Aprovação só roda em request `pending` — re-aprovar uma já-handled
     * dispararia `UserVerified` duas vezes e sobrescreveria o tier do
     * primeiro admin.
     */
    private function approveRequest(Context $context): VerificationRequest
    {
        $actor = $context->getActor();

        $request = $this->findOrFail($context);
        $this->assertPending($request);

        /** @var User|null $user */
        $user = User::query()->find($request->user_id);
        if (! $user) {
            throw new ValidationException([
                'user' => $this->translator->trans('ramon-verified.api.user_missing'),
            ]);
        }

        if ($this->verifiedStatus->isVerified($user)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_verified'),
            ]);
        }

        $now = Carbon::now();
        $note = $this->extractNote($context);
        $tier = $this->tiers->resolveRequestedTierId($this->readRequestedTier($context));

        $this->transaction(function () use ($request, $user, $actor, $now, $note, $tier) {
            $request->status     = VerificationRequest::STATUS_APPROVED;
            $request->handled_by = (int) $actor->id;
            $request->handled_at = $now;
            $request->updated_at = $now;
            $request->admin_note = $note;
            $request->save();

            $this->verifiedStatus->mark($user, (int) $actor->id, $tier, $now);

            $this->retention->onRequestHandled($request);
        });

        $this->events->dispatch(new UserVerified($user, $actor));

        return $request;
    }

    private function rejectRequest(Context $context): VerificationRequest
    {
        $actor = $context->getActor();

        $request = $this->findOrFail($context);
        $this->assertPending($request);

        $now = Carbon::now();
        $note = $this->extractNote($context);

        $this->transaction(function () use ($request, $actor, $now, $note) {
            $request->status     = VerificationRequest::STATUS_REJECTED;
            $request->handled_by = (int) $actor->id;
            $request->handled_at = $now;
            $request->updated_at = $now;
            $request->admin_note = $note;
            $request->save();

            $this->retention->onRequestHandled($request);
        });

        return $request;
    }

    private function revokeRequest(Context $context): VerificationRequest
    {
        $actor = $context->getActor();

        $request = $this->findOrFail($context);

        /** @var User|null $user */
        $user = User::query()->find($request->user_id);

        $now = Carbon::now();
        $note = $this->extractNote(
            $context,
            $this->translator->trans('ramon-verified.api.revoked_default_note')
        );

        $this->transaction(function () use ($request, $user, $actor, $now, $note) {
            $request->status     = VerificationRequest::STATUS_REJECTED;
            $request->handled_by = (int) $actor->id;
            $request->handled_at = $now;
            $request->updated_at = $now;
            $request->admin_note = $note;
            $request->save();

            if ($user) {
                $this->verifiedStatus->clear($user);
            }

            $this->retention->onRequestHandled($request);
        });

        return $request;
    }

    private function findOrFail(Context $context): VerificationRequest
    {
        /** @var VerificationRequest|null $request */
        $request = VerificationRequest::query()->find($context->modelId);

        if (! $request) {
            throw new ValidationException([
                'id' => $this->translator->trans('ramon-verified.api.not_found'),
            ]);
        }

        return $request;
    }

    private function assertPending(VerificationRequest $request): void
    {
        if (! $request->isPending()) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_handled'),
            ]);
        }
    }

    /**
     * Checa que o arquivo apontado pelo token ainda existe no disco. Usado
     * pelo Create para recusar tokens stale cujo arquivo foi varrido por
     * upload subsequente.
     */
    private function documentFileExists(User $user, string $path): bool
    {
        $relative = $this->pathResolver->resolveRelative($path, (int) $user->id);
        if ($relative === null) return false;

        return $this->filesystem->disk(DocumentPathResolver::DISK)->exists($relative);
    }

    /**
     * Reduz o `documentType` enviado ao allowlist configurado pelo admin.
     * Devolve null quando o input é inválido — preserva trilho de auditoria
     * mas impede uso da coluna como sidechannel.
     */
    private function resolveDocumentType(string $requested): ?string
    {
        $configured = $this->settings->get('ramon-verified.document_types');
        $list       = is_string($configured) ? json_decode($configured, true) : null;

        if (! is_array($list) || empty($list)) {
            return $requested;
        }

        $valid = [];
        foreach ($list as $row) {
            if (! is_array($row)) continue;
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            if ($id !== '') $valid[mb_substr($id, 0, 32)] = true;
        }

        return isset($valid[$requested]) ? $requested : null;
    }

    private function extractNote(Context $context, ?string $default = null): ?string
    {
        $body = $context->body();
        $note = $body['meta']['adminNote'] ?? $body['data']['attributes']['adminNote'] ?? null;

        if (! is_string($note)) {
            return $default;
        }

        $note = trim($note);

        if ($note === '') {
            return $default;
        }

        return mb_substr($note, 0, 1000);
    }

    /**
     * Lê o tier requisitado de qualquer um dos shapes aceitos no body:
     * `meta.tier` ou `data.attributes.tier`. A normalização (default/null)
     * fica em `TierResolver::resolveRequestedTierId()`.
     */
    private function readRequestedTier(Context $context): ?string
    {
        $body = $context->body();
        $raw = $body['meta']['tier'] ?? $body['data']['attributes']['tier'] ?? null;
        return is_string($raw) ? $raw : null;
    }
}
