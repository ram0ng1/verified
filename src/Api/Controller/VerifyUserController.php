<?php

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Permite a um admin (ou ator com `verified.verifyUsers`) virar o flag
 * `is_verified` diretamente, contornando o workflow padrão de requests.
 * Uma linha `VerificationRequest` continua sendo gravada para o histórico.
 *
 * - POST   /verified/users/{id}/verify     → marca verificado
 * - DELETE /verified/users/{id}/verify     → revoga verificação
 */
class VerifyUserController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Dispatcher $events,
        protected DocumentRetention $retention,
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tiers,
        protected VerifiedStatus $verifiedStatus
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $rawId  = $request->getAttribute('id') ?? ($request->getQueryParams()['id'] ?? 0);
        $userId = (int) $rawId;
        if ($userId <= 0) {
            throw new ValidationException(['id' => $this->translator->trans('ramon-verified.api.user_missing')]);
        }

        $isSelf = (int) $actor->id === $userId;
        $method = strtoupper($request->getMethod());

        $this->assertCanActOn($actor, $isSelf, $method);

        /** @var User|null $target */
        $target = User::query()->find($userId);
        if (! $target) {
            throw new ValidationException(['id' => $this->translator->trans('ramon-verified.api.user_missing')]);
        }

        $body = (array) $request->getParsedBody();
        $note = isset($body['adminNote']) && is_string($body['adminNote'])
            ? mb_substr(trim($body['adminNote']), 0, 1000)
            : null;

        $tierId = isset($body['tier']) && is_string($body['tier'])
            ? trim($body['tier'])
            : null;

        $now = Carbon::now();

        if ($method === 'DELETE') {
            if ($isSelf && ! $actor->isAdmin()) {
                $note = $this->translator->trans('ramon-verified.api.self_revoked_note');
            }
            return $this->unverify($target, $actor, $note, $now);
        }

        return $this->verify($target, $actor, $note, $tierId, $now);
    }

    /**
     * Gate explícito por rota (§3, §4): cross-user sempre exige
     * `verifyUsers`; self-revoke é uma capabilidade separada (`selfRevoke`,
     * default MEMBER_ID) que admins podem desabilitar sem afetar a
     * permissão de moderação. Sem o gate, qualquer membro verificado
     * dropa o próprio badge bypassando o painel admin.
     */
    private function assertCanActOn(User $actor, bool $isSelf, string $method): void
    {
        if (! $isSelf) {
            $actor->assertCan('verified.verifyUsers');
            return;
        }

        if ($method === 'DELETE') {
            if (! $actor->hasPermission('verified.selfRevoke')
                && ! $actor->hasPermission('verified.verifyUsers')) {
                $actor->assertCan('verified.selfRevoke');
            }
            return;
        }

        $actor->assertCan('verified.verifyUsers');
    }

    /**
     * Aprovação direta envolvida em transação. O dispatch do `UserVerified`
     * fica FORA do bloco para publicar apenas após commit. O pós-processamento
     * de retenção mira a lista de IDs capturada antes do UPDATE — filtro por
     * `handled_at` colidiria com verificações gravadas no mesmo segundo.
     */
    private function verify(User $target, User $actor, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        if ($this->verifiedStatus->isVerified($target)) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_verified')]);
        }

        $resolvedTierId = $this->tiers->resolveRequestedTierId($tierId);
        $adminNote      = $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note');

        VerificationRequest::runInTransaction(function () use ($target, $actor, $now, $resolvedTierId, $adminNote) {
            $flippedIds = VerificationRequest::query()
                ->where('user_id', $target->id)
                ->where('status', VerificationRequest::STATUS_PENDING)
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

            $this->verifiedStatus->mark($target, (int) $actor->id, $resolvedTierId, $now);

            VerificationRequest::query()
                ->whereIn('id', $flippedIds)
                ->get()
                ->each(fn (VerificationRequest $req) => $this->retention->onRequestHandled($req));
        });

        $this->events->dispatch(new UserVerified($target, $actor));

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    'isVerified'   => true,
                    'verifiedAt'   => $now->toRfc3339String(),
                    'verifiedTier' => $resolvedTierId,
                ],
            ],
        ], 200);
    }

    /**
     * Revogação: cobre os dois shapes possíveis de "verified".
     *
     *  - **Manual** (linha em `user_verification` com `is_verified=true`):
     *    apaga a linha e grava REJECTED na auditoria. Se o usuário ainda
     *    pertencer a `autoGroups`, o auto-tier reaparece — `meta.autoTierPersists`
     *    avisa o caller.
     *  - **Auto-tier** (sem linha manual, mas o `TierResolver` devolve tier
     *    via grupo): grava um tombstone `auto_revoked_at` para que o
     *    `TierResolver` pare de devolver auto-tier para esse usuário.
     *    Necessário porque o user não controla os grupos a que pertence,
     *    e sem tombstone clicar "Revoke" não tinha efeito.
     */
    private function unverify(User $target, User $actor, ?string $note, Carbon $now): JsonResponse
    {
        $hasManualVerification = $this->verifiedStatus->isVerified($target);
        $hasAutoTier = ! $hasManualVerification && $this->tiers->resolveAutoTier($target) !== null;

        if (! $hasManualVerification && ! $hasAutoTier) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.not_verified')]);
        }

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

        $target->load('groups');

        /*
         * Após `markAutoRevoked` o TierResolver respeita o tombstone e
         * devolve `null`, então `autoTierPersists` só aparece quando havia
         * uma verificação manual sobreposta sobre um grupo auto-tier-eligible
         * — semântica original preservada.
         */
        $autoTier = $this->tiers->resolveAutoTier($target);
        $autoTierStillEffective = $autoTier !== null && $this->tiers->resolveTierId($target) !== null;

        $meta = $autoTierStillEffective
            ? ['autoTierPersists' => [
                'id'    => $autoTier['id'],
                'label' => $autoTier['label'],
            ]]
            : new \stdClass();

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    'isVerified'   => $autoTierStillEffective,
                    'verifiedAt'   => null,
                    'verifiedTier' => $autoTierStillEffective ? $autoTier['id'] : null,
                ],
            ],
            'meta' => $meta,
        ], 200);
    }
}
