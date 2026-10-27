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
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * POST /verified/users/{id}/verify: verificação direta. Exige
 * `verified.verifyUsers`, inclusive para si mesmo.
 */
class VerifyUserController extends AbstractDirectVerificationController
{
    protected function assertCanActOn(User $actor, bool $isSelf): void
    {
        $actor->assertCan('verified.verifyUsers');
    }

    protected function act(User $actor, User $target, bool $isSelf, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        return $this->verify($target, $actor, $note, $tierId, $now);
    }

    /**
     * Aprovação direta. A mutação (transação + retenção + dispatch do
     * `UserVerified`) vive em `VerificationRequestService::verifyDirect()`,
     * compartilhada com o fluxo de aprovação de pedidos — o controller só
     * monta o envelope JSON da resposta.
     */
    private function verify(User $target, User $actor, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        $resolvedTierId = $this->service->verifyDirect($target, $actor, $note, $tierId, $now);

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
}
