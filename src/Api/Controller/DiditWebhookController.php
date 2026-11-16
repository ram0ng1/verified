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

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditConfig;
use Ramon\Verified\Didit\DiditStatusSync;
use Ramon\Verified\Didit\WebhookSignature;
use Ramon\Verified\Models\VerificationRequest;

/**
 * POST /verified/didit/webhook: eventos da Didit. Sem sessão nem CSRF; a
 * autenticação é a assinatura HMAC com o segredo do destino.
 */
class DiditWebhookController implements RequestHandlerInterface
{
    public function __construct(
        protected DiditConfig $config,
        protected DiditStatusSync $sync
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->config->enabled()) {
            return new JsonResponse(['error' => 'disabled'], 404);
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->getContents();

        if (! WebhookSignature::verify($request, $raw, $this->config->webhookSecret())) {
            return new JsonResponse(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return new JsonResponse(['error' => 'invalid payload'], 400);
        }

        $type = (string) ($payload['webhook_type'] ?? '');
        $sessionId = (string) ($payload['session_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        // Só a mudança de status de sessão importa aqui; o resto é confirmado e ignorado.
        if ($type !== 'status.updated' || $status === '' || ! DiditClient::isSessionId($sessionId)) {
            return new JsonResponse(['ignored' => true]);
        }

        /** @var VerificationRequest|null $verification */
        $verification = VerificationRequest::query()
            ->where('provider', DiditStatusSync::PROVIDER)
            ->where('external_id', strtolower($sessionId))
            ->first();

        if (! $verification) {
            return new JsonResponse(['ignored' => true]);
        }

        $this->sync->apply($verification, $status);

        return new JsonResponse(['ok' => true]);
    }
}
