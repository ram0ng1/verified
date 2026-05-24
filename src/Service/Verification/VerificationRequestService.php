<?php

namespace Ramon\Verified\Service\Verification;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Regra de negócio dos pedidos de verificação. Extraída do
 * `VerificationRequestResource` para manter o resource focado em schema,
 * endpoints e visibilidade — o resource só orquestra as chamadas.
 */
class VerificationRequestService
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
     * Cria um pedido pendente. A checagem "já tem pendente?" e o insert rodam
     * dentro de uma transação com `lockForUpdate` sobre o índice composto
     * `(user_id, status)` — sem a trava, dois requests concorrentes do mesmo
     * ator passariam ambos pela checagem e gravariam pedidos duplicados.
     */
    public function create(Context $context): VerificationRequest
    {
        $actor = $context->getActor();
        $actor->assertRegistered();

        // Gate de permissão `verified.request` é admin-configurável via
        // `ramon-verified.gate_by_permission`:
        //
        // - **off (default)**: qualquer autenticado abre o próprio pedido —
        //   inclusive não-confirmados / em fila do `flarum-approval`.
        //   Fluxo de signup com checkbox depende disso.
        // - **on**: restaura `assertCan('verified.request')` — admin pode
        //   revogar a permissão de grupos via UI de Permissões e bloquear.
        //
        // Demais gates ficam de pé independente: `requests_open` (kill
        // switch global), duplicata pendente (lockForUpdate abaixo) e
        // já-verificado.
        if ((bool) $this->settings->get('ramon-verified.gate_by_permission', false)) {
            $actor->assertCan('verified.request');
        }

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

        return VerificationRequest::runInTransaction(function () use ($actor, $reason, $documentType, $documentPath) {
            $existing = VerificationRequest::query()
                ->where('user_id', $actor->id)
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new ValidationException([
                    'status' => $this->translator->trans('ramon-verified.api.already_pending'),
                ]);
            }

            $now = Carbon::now();

            $request = new VerificationRequest();
            $request->user_id       = (int) $actor->id;
            $request->status        = VerificationRequest::STATUS_PENDING;
            $request->reason        = $reason ?: null;
            $request->document_type = $documentType ?: null;
            $request->document_path = $documentPath ?: null;
            $request->created_at    = $now;
            $request->updated_at    = $now;
            $request->save();

            return $request;
        });
    }

    /**
     * Aprovação só roda em request `pending` — re-aprovar uma já-handled
     * dispararia `UserVerified` duas vezes e sobrescreveria o tier do
     * primeiro admin.
     */
    public function approve(Context $context): VerificationRequest
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

        VerificationRequest::runInTransaction(function () use ($request, $user, $actor, $now, $note, $tier) {
            $request->status     = VerificationRequest::STATUS_APPROVED;
            $request->handled_by = (int) $actor->id;
            $request->handled_at = $now;
            $request->updated_at = $now;
            $request->admin_note = $note;
            $request->save();

            $this->finalizeApproval($user, $actor, $tier, $now, [$request]);
        });

        $this->events->dispatch(new UserVerified($user, $actor));

        return $request;
    }

    /**
     * Aprovação direta por um moderador via rota POST /verified/users/{id}/verify,
     * sem depender de um pedido pendente prévio. Fecha qualquer pedido pendente
     * do alvo — ou grava uma linha de auditoria APPROVED quando não há nenhum —
     * e compartilha com approve() o núcleo `finalizeApproval()`. Devolve o tier
     * resolvido para o caller montar a resposta. O dispatch de `UserVerified`
     * fica fora da transação para publicar apenas após commit.
     *
     * A varredura de pendentes roda com `lockForUpdate` sobre `(user_id, status)`
     * — a mesma trava de `create()`. Sem ela, um `create()` concorrente gravaria
     * um pedido pendente entre a varredura e o commit, deixando-o sem ser fechado.
     */
    public function verifyDirect(User $target, User $actor, ?string $note, ?string $tierId, Carbon $now): string
    {
        if ($this->verifiedStatus->isVerified($target)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_verified'),
            ]);
        }

        $resolvedTierId = $this->tiers->resolveRequestedTierId($tierId);
        $adminNote      = $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note');

        VerificationRequest::runInTransaction(function () use ($target, $actor, $now, $resolvedTierId, $adminNote) {
            $flippedIds = VerificationRequest::query()
                ->where('user_id', $target->id)
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($flippedIds)) {
                VerificationRequest::query()
                    ->whereIn('id', $flippedIds)
                    ->update([
                        'status'     => VerificationRequest::STATUS_APPROVED,
                        'handled_by' => (int) $actor->id,
                        'handled_at' => $now,
                        'updated_at' => $now,
                        'admin_note' => $adminNote,
                    ]);
            } else {
                $insertedId = (int) VerificationRequest::query()->insertGetId([
                    'user_id'    => (int) $target->id,
                    'status'     => VerificationRequest::STATUS_APPROVED,
                    'reason'     => null,
                    'admin_note' => $adminNote,
                    'handled_by' => (int) $actor->id,
                    'handled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $flippedIds = [$insertedId];
            }

            $handled = VerificationRequest::query()->whereIn('id', $flippedIds)->get();
            $this->finalizeApproval($target, $actor, $resolvedTierId, $now, $handled);
        });

        $this->events->dispatch(new UserVerified($target, $actor));

        return $resolvedTierId;
    }

    /**
     * Revogação direta via rota DELETE /verified/users/{id}/verify. Grava a
     * linha de auditoria REJECTED e limpa o estado verified. Quando a
     * verificação era apenas auto-tier (sem linha manual) persiste o tombstone
     * de opt-out em vez de deletar. A apresentação do auto-tier remanescente
     * fica no controller — aqui só a mutação.
     */
    public function unverifyDirect(User $target, User $actor, ?string $note, Carbon $now, bool $hasManualVerification): void
    {
        $defaultNote = $this->translator->trans('ramon-verified.api.revoked_default_note');

        VerificationRequest::runInTransaction(function () use ($target, $actor, $note, $now, $defaultNote, $hasManualVerification) {
            VerificationRequest::query()->insert([
                'user_id'    => (int) $target->id,
                'status'     => VerificationRequest::STATUS_REJECTED,
                'reason'     => null,
                'admin_note' => $note ?: $defaultNote,
                'handled_by' => (int) $actor->id,
                'handled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($hasManualVerification) {
                $this->verifiedStatus->clear($target);
            } else {
                $this->verifiedStatus->markAutoRevoked($target, $now);
            }
        });
    }

    /**
     * Núcleo compartilhado por approve() e verifyDirect(): marca o usuário como
     * verificado e aplica a política de retenção a cada pedido fechado. Roda
     * sempre dentro da transação do caller.
     *
     * @param iterable<VerificationRequest> $handledRequests
     */
    private function finalizeApproval(User $user, User $actor, ?string $tier, Carbon $now, iterable $handledRequests): void
    {
        $this->verifiedStatus->mark($user, (int) $actor->id, $tier, $now);

        foreach ($handledRequests as $request) {
            $this->retention->onRequestHandled($request);
        }
    }

    public function reject(Context $context): VerificationRequest
    {
        $actor = $context->getActor();

        $request = $this->findOrFail($context);
        $this->assertPending($request);

        $now = Carbon::now();
        $note = $this->extractNote($context);

        VerificationRequest::runInTransaction(function () use ($request, $actor, $now, $note) {
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

    public function revoke(Context $context): VerificationRequest
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

        VerificationRequest::runInTransaction(function () use ($request, $user, $actor, $now, $note) {
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

    /**
     * Resolve o `VerificationRequest` do contexto ou lança
     * `ModelNotFoundException`, que o Flarum mapeia para HTTP 404. Um modelo
     * ausente é recurso inexistente, não erro de validação (422).
     */
    private function findOrFail(Context $context): VerificationRequest
    {
        /** @var VerificationRequest $request */
        $request = VerificationRequest::query()->findOrFail($context->modelId);

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
     * pelo create para recusar tokens stale cujo arquivo foi varrido por
     * upload subsequente.
     */
    private function documentFileExists(User $user, string $path): bool
    {
        $relative = $this->pathResolver->resolveRelative($path, (int) $user->id);
        if ($relative === null) {
            return false;
        }

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
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            if ($id !== '') {
                $valid[mb_substr($id, 0, 32)] = true;
            }
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
