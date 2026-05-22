<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;

/**
 * Admin-only status probe — tells the front end whether libsodium is
 * available, whether a public key has been generated, and whether the
 * private key was actually pasted into config.php. Refreshed on every
 * admin panel mount so the warning state stays accurate even if config.php
 * is edited live.
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
        $actor->assertRegistered();
        $actor->assertAdmin();

        return new JsonResponse($this->cipher->status(), 200);
    }
}
