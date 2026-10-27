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
     * Verificação manual: apaga a linha; se o usuário ainda estiver num
     * `autoGroup`, o auto-tier volta e `meta.autoTierPersists` avisa o caller.
     *
     * Só auto-tier: grava o tombstone `auto_revoked_at`. O usuário não controla os
     * próprios grupos, então sem ele o "Revoke" não teria efeito.
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
         * Com o tombstone o auto-tier some, então `autoTierPersists` só aparece quando
         * havia verificação manual sobre um grupo elegível.
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
