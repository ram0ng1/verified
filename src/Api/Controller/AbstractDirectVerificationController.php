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
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Service\Verification\VerificationRequestService;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Base das rotas de verificação direta (`/verified/users/{id}/verify`): um
 * moderador verifica ou revoga sem passar pela fila de solicitações. Uma linha
 * em `verification_requests` continua sendo gravada para o histórico.
 */
abstract class AbstractDirectVerificationController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected TierResolver $tiers,
        protected VerifiedStatus $verifiedStatus,
        protected VerificationRequestService $service
    ) {
    }

    abstract protected function act(User $actor, User $target, bool $isSelf, ?string $note, ?string $tierId, Carbon $now): JsonResponse;

    abstract protected function assertCanActOn(User $actor, bool $isSelf): void;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $userId = (int) ($request->getAttribute('id') ?? ($request->getQueryParams()['id'] ?? 0));
        if ($userId <= 0) {
            throw new ValidationException(['id' => $this->translator->trans('ramon-verified.api.user_missing')]);
        }

        $isSelf = (int) $actor->id === $userId;

        $this->assertCanActOn($actor, $isSelf);

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

        return $this->act($actor, $target, $isSelf, $note, $tierId, Carbon::now());
    }
}
