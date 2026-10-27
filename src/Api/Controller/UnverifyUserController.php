<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * DELETE /verified/users/{id}/verify: revogação direta. Em outra pessoa exige
 * `verified.verifyUsers`; em si mesmo, `verified.selfRevoke` (o caminho da
 * troca de avatar bloqueado), que o admin pode tirar sem mexer na moderação.
 */
class UnverifyUserController extends AbstractDirectVerificationController
{
    protected function assertCanActOn(User $actor, bool $isSelf): void
    {
        if (! $isSelf) {
            $actor->assertCan('verified.verifyUsers');

            return;
        }

        if (! $actor->hasPermission('verified.selfRevoke')
            && ! $actor->hasPermission('verified.verifyUsers')) {
            throw new PermissionDeniedException();
        }
    }

    protected function act(User $actor, User $target, bool $isSelf, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        if ($isSelf && ! $actor->isAdmin()) {
            $note = $this->translator->trans('ramon-verified.api.self_revoked_note');
        }

        return $this->unverify($target, $actor, $note, $now);
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

        $this->service->unverifyDirect($target, $actor, $note, $now, $hasManualVerification);

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
