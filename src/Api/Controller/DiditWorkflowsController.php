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

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditException;

/**
 * GET /verified/didit/workflows: os workflows da conta, para o admin escolher.
 * Serve também como teste da chave.
 */
class DiditWorkflowsController implements RequestHandlerInterface
{
    public function __construct(
        protected DiditClient $client
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        try {
            return new JsonResponse(['ok' => true, 'workflows' => $this->client->listWorkflows()]);
        } catch (DiditException $e) {
            // O status da Didit (401 chave inválida, 403 sem permissão) vai para o admin.
            return new JsonResponse(['ok' => false, 'status' => $e->getCode(), 'workflows' => []]);
        }
    }
}
