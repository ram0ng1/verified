<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
use Ramon\Verified\Event\UserUnverified;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

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
     * A checagem de pendente e o insert rodam numa transação com `lockForUpdate`;
     * sem a trava, dois requests concorrentes do mesmo ator gravariam duplicados.
     */
    public function create(Context $context): VerificationRequest
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
            && $this->documentFileExists($actor, $documentPath)
            // Um arquivo já anexado a outra solicitação não pode ser reusado:
            // apagar a solicitação nova (o dono pode, enquanto pendente)
            // levaria junto a evidência da antiga.
            && ! VerificationRequest::query()->where('document_path', $documentPath)->exists();

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
     * Re-aprovar um pedido já tratado dispararia `UserVerified` de novo e
     * sobrescreveria o tier do primeiro admin.
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
            $this->lockPending($request);

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
     * Fecha os pedidos pendentes do alvo, ou grava uma linha APPROVED quando não há
     * nenhum. `UserVerified` é disparado fora da transação, só após o commit.
     *
     * A varredura de pendentes usa a mesma trava de `create()`; sem ela, um pedido
     * criado entre a varredura e o commit ficaria aberto.
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
     * Se a verificação era só auto-tier, grava o tombstone de opt-out em vez de
     * apagar a linha.
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

        $this->events->dispatch(new UserUnverified($target, $actor));
    }

    /**
     * Roda sempre dentro da transação do caller.
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
            $this->lockPending($request);

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

        // Só uma aprovação pode ser revogada. Sem isto, "revogar" uma linha
        // pendente virava uma rejeição que também limpava a verificação, e
        // revogar uma linha antiga reescrevia quem a tinha tratado.
        if ($request->status !== VerificationRequest::STATUS_APPROVED) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.revoke_not_approved'),
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->find($request->user_id);
        $wasVerified = $user !== null && $this->verifiedStatus->isVerified($user);

        $now = Carbon::now();
        $note = $this->extractNote(
            $context,
            $this->translator->trans('ramon-verified.api.revoked_default_note')
        );

        VerificationRequest::runInTransaction(function () use ($request, $user, $actor, $now, $note) {
            $this->lockLatestApproval($request);

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

        if ($user !== null && $wasVerified) {
            $this->events->dispatch(new UserUnverified($user, $actor));
        }

        return $request;
    }

    /**
     * Model ausente é 404 (`ModelNotFoundException`), não erro de validação.
     */
    private function findOrFail(Context $context): VerificationRequest
    {
        // O endpoint já carregou o model (404 se não existe); a consulta só
        // roda quando o serviço é chamado fora de um endpoint.
        if ($context->model instanceof VerificationRequest) {
            return $context->model;
        }

        /** @var VerificationRequest $request */
        $request = VerificationRequest::query()->findOrFail($context->modelId);

        return $request;
    }

    /**
     * O `assertPending()` antes da transação é só a resposta rápida. Sem esta
     * releitura travada, aprovar e rejeitar ao mesmo tempo (dois moderadores, ou um
     * clique duplo) passariam os dois.
     */
    private function lockPending(VerificationRequest $request): void
    {
        $status = VerificationRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->value('status');

        if ($status !== VerificationRequest::STATUS_PENDING) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_handled'),
            ]);
        }
    }

    /**
     * Após "revogar direto" e "verificar direto", a aprovação antiga continua
     * `approved` no histórico; revogá-la apagaria a verificação nova.
     */
    private function lockLatestApproval(VerificationRequest $request): void
    {
        $status = VerificationRequest::query()
            ->whereKey($request->getKey())
            ->lockForUpdate()
            ->value('status');

        if ($status !== VerificationRequest::STATUS_APPROVED) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.revoke_not_approved'),
            ]);
        }

        $newer = VerificationRequest::query()
            ->where('user_id', $request->user_id)
            ->where('status', VerificationRequest::STATUS_APPROVED)
            ->where('id', '>', $request->getKey())
            ->exists();

        if ($newer) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.revoke_not_latest'),
            ]);
        }
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
     * Recusa tokens cujo arquivo já foi varrido por um upload posterior.
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
     * Fora da allowlist do admin vira null: preserva o registro, mas impede o uso
     * da coluna como canal lateral.
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
     * Aceita `meta.tier` ou `data.attributes.tier`; a normalização fica em
     * `TierResolver::resolveRequestedTierId()`.
     */
    private function readRequestedTier(Context $context): ?string
    {
        $body = $context->body();
        $raw = $body['meta']['tier'] ?? $body['data']['attributes']['tier'] ?? null;

        return is_string($raw) ? $raw : null;
    }
}
