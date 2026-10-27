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

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Job\PurgeOrphanedDocumentsJob;
use Ramon\Verified\Models\VerificationRequest;

/**
 * A chave pública vai para os settings; a privada volta uma única vez na
 * resposta para o admin colar no `config.php`. Perdê-la significa gerar outro
 * par, o que inutiliza os documentos já cifrados.
 *
 * Rotacionar um par existente exige `acknowledgeLoss=true`, e os documentos
 * cifrados com a chave antiga vão para purga via `PurgeOrphanedDocumentsJob`.
 * A contagem devolvida é capturada antes do `forget`, para não incluir uploads
 * concorrentes que já chegarão em texto claro.
 */
class GenerateKeypairController implements RequestHandlerInterface
{
    public function __construct(
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator,
        protected LoggerInterface $logger,
        protected BusDispatcher $bus
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        if (! $this->cipher->isAvailable()) {
            throw new ValidationException([
                'encryption' => $this->translator->trans('ramon-verified.api.encryption.libsodium_missing'),
            ]);
        }

        $hasPublic = $this->cipher->hasPublicKey();

        $body = (array) $request->getParsedBody();
        $acknowledged = ! empty($body['acknowledgeLoss']);

        $orphanedCandidates = 0;
        $rotatedAt = time();

        if ($hasPublic) {
            if (! $acknowledged) {
                throw new ValidationException([
                    'acknowledgeLoss' => $this->translator->trans('ramon-verified.api.encryption.acknowledge_loss_required'),
                ]);
            }

            $orphanedCandidates = (int) VerificationRequest::query()
                ->whereNotNull('document_path')
                ->count();

            $this->cipher->forgetPublicKey();
        }

        $pair = $this->cipher->generateKeypair();

        if ($hasPublic) {
            $this->bus->dispatch(new PurgeOrphanedDocumentsJob($rotatedAt));
        }

        $this->logger->warning('verified: encryption keypair regenerated', [
            'actor_id'             => (int) $actor->id,
            'actor_username'       => (string) $actor->username,
            'orphaned_candidates'  => $orphanedCandidates,
            'rotation'             => $hasPublic,
        ]);

        return (new JsonResponse([
            'publicKey'         => $pair['public'],
            'privateKey'        => $pair['private'],
            'configKey'         => DocumentCipher::CONFIG_PRIVATE_KEY,
            'orphanedDocuments' => $orphanedCandidates,
        ], 200))
            ->withHeader('Cache-Control', 'no-store, max-age=0, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Clear-Site-Data', '"cache"');
    }
}
