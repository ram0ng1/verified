<?php

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;
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
        protected ConnectionInterface $db,
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
        if (! $isSelf) {
            $actor->assertCan('verified.verifyUsers');
        }

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

        $method = strtoupper($request->getMethod());
        $now    = Carbon::now();

        if ($method === 'DELETE') {
            if ($isSelf && ! $actor->isAdmin()) {
                $note = $this->translator->trans('ramon-verified.api.self_revoked_note');
            }
            return $this->unverify($target, $actor, $note, $now);
        }

        if ($isSelf && ! $actor->isAdmin()) {
            $actor->assertCan('verified.verifyUsers');
        }

        return $this->verify($target, $actor, $note, $tierId, $now);
    }

    /**
     * Aprovação direta envolvida em transação. O dispatch do `UserVerified`
     * fica FORA do bloco para publicar apenas após commit.
     */
    private function verify(User $target, User $actor, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        if ($this->verifiedStatus->isVerified($target)) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_verified')]);
        }

        $resolvedTierId = $this->resolveTierId($tierId);
        $adminNote      = $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note');

        $this->db->transaction(function () use ($target, $actor, $now, $resolvedTierId, $adminNote) {
            $flippedRows = VerificationRequest::query()
                ->where('user_id', $target->id)
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->update([
                    'status'     => VerificationRequest::STATUS_APPROVED,
                    'handled_by' => (int) $actor->id,
                    'handled_at' => $now,
                    'updated_at' => $now,
                    'admin_note' => $adminNote,
                ]);

            if ($flippedRows === 0) {
                VerificationRequest::query()->insert([
                    'user_id'    => (int) $target->id,
                    'status'     => VerificationRequest::STATUS_APPROVED,
                    'reason'     => null,
                    'admin_note' => $adminNote,
                    'handled_by' => (int) $actor->id,
                    'handled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->verifiedStatus->mark($target, (int) $actor->id, $resolvedTierId, $now);

            VerificationRequest::query()
                ->where('user_id', $target->id)
                ->where('handled_at', $now)
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
     * Revogação: limpa as colunas manuais e grava REJECTED na auditoria.
     * Quando o alvo ainda pertence a algum `autoGroups` de um tier, o
     * resolver continua dando "verified" — `meta.autoTierPersists` informa
     * o caller para que o toast diga a verdade.
     */
    private function unverify(User $target, User $actor, ?string $note, Carbon $now): JsonResponse
    {
        if (! $this->verifiedStatus->isVerified($target)) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.not_verified')]);
        }

        $defaultNote = $this->translator->trans('ramon-verified.api.revoked_default_note');

        $this->db->transaction(function () use ($target, $actor, $note, $now, $defaultNote) {
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

            $this->verifiedStatus->clear($target);
        });

        $target->load('groups');
        $autoTier = $this->tiers->resolveAutoTier($target);

        $meta = $autoTier !== null
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
                    'isVerified'   => $autoTier !== null,
                    'verifiedAt'   => null,
                    'verifiedTier' => $autoTier['id'] ?? null,
                ],
            ],
            'meta' => $meta,
        ], 200);
    }

    /**
     * Resolve o tier requisitado contra a lista configurada. Falls back ao
     * default (ou ao primeiro tier configurado) para que input ausente ou
     * inválido nunca bloqueie um verify. Lista vazia devolve `null` —
     * resource layer renderiza fallback.
     */
    private function resolveTierId(?string $requested): ?string
    {
        $tiers = TierConfig::fromSettings($this->settings);
        if (empty($tiers)) {
            return null;
        }

        if ($requested) {
            $found = TierConfig::findById($tiers, $requested);
            if ($found) return $found['id'];
        }

        $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
        return $fallback['id'];
    }
}
