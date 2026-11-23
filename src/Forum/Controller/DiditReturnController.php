<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Forum\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditException;
use Ramon\Verified\Didit\DiditStatusSync;
use Ramon\Verified\Models\VerificationRequest;

/**
 * GET /verified/didit/return: para onde a Didit manda o usuário ao terminar.
 * O status da query string não é confiável; a decisão é buscada na API, e só
 * para uma sessão que pertence a quem está logado. Cobre o fórum sem webhook.
 */
class DiditReturnController implements RequestHandlerInterface
{
    public function __construct(
        protected DiditClient $client,
        protected DiditStatusSync $sync,
        protected UrlGenerator $url,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $sessionId = (string) ($request->getQueryParams()['verificationSessionId'] ?? '');

        if (! $actor->isGuest() && DiditClient::isSessionId($sessionId)) {
            /** @var VerificationRequest|null $verification */
            $verification = VerificationRequest::query()
                ->where('user_id', $actor->id)
                ->where('provider', DiditStatusSync::PROVIDER)
                ->where('external_id', strtolower($sessionId))
                ->first();

            if ($verification) {
                try {
                    $decision = $this->client->getDecision($sessionId);
                    $status = (string) ($decision['status'] ?? '');
                    if ($status !== '') {
                        $this->sync->apply($verification, $status);
                    }
                } catch (DiditException $e) {
                    // O webhook ainda pode trazer o resultado.
                    $this->logger->warning('verified: could not fetch Didit decision', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
                }
            }
        }

        return new RedirectResponse($this->url->to('forum')->route('settings'));
    }
}
