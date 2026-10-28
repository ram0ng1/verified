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
use Ramon\Verified\Crypto\DocumentCipher;

/**
 * Diz ao admin se há libsodium, chave pública gerada e chave privada no
 * `config.php`. Consultado a cada montagem do painel, pois o `config.php` pode
 * mudar a qualquer momento.
 */
class EncryptionStatusController implements RequestHandlerInterface
{
    public function __construct(
        protected DocumentCipher $cipher
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        return new JsonResponse($this->cipher->status(), 200);
    }
}
