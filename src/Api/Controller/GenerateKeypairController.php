<?php

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
 * Gera um par de chaves de criptografia. A pública é persistida como
 * setting; a privada volta UMA VEZ no corpo da resposta para o admin
 * colar manualmente em `config.php`. Se perdida, a única recuperação é
 * gerar de novo — o que destrói todos os documentos previamente cifrados.
 *
 * Fluxo:
 * - Sem chave pública prévia → gera livremente.
 * - Estado saudável (público + privado coincidem) → rotação só com
 *   `acknowledgeLoss=true`. Documentos cifrados pela chave antiga são
 *   enfileirados para purga via `PurgeOrphanedDocumentsJob`.
 * - Estado quebrado (público sem privado) → mesmo shape; documentos
 *   cifrados também são enfileirados porque já não eram legíveis.
 *
 * A purga é despachada como job — em queue driver `sync` (default) roda
 * inline; sob driver real (`redis`, `database`) acontece em worker sem
 * amarrar o request do admin. Em ambos os casos, a contagem retornada é
 * de candidatos enfileirados, capturada antes do `forget` para não
 * contabilizar uploads concorrentes que virão plaintext.
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
        $actor->assertRegistered();
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
            $this->bus->dispatch(new PurgeOrphanedDocumentsJob());
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
