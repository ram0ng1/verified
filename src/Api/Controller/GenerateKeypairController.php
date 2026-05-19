<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

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
 *   apagados ANTES da nova chave entrar em jogo.
 * - Estado quebrado (público sem privado) → mesmo shape; documentos
 *   cifrados são apagados porque já não eram legíveis.
 */
class GenerateKeypairController implements RequestHandlerInterface
{
    public function __construct(
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator,
        protected Factory $filesystem,
        protected DocumentPathResolver $resolver,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if (! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }

        if (! $this->cipher->isAvailable()) {
            throw new ValidationException([
                'encryption' => $this->translator->trans('ramon-verified.api.encryption.libsodium_missing'),
            ]);
        }

        $hasPublic = $this->cipher->hasPublicKey();

        $body = (array) $request->getParsedBody();
        $acknowledged = ! empty($body['acknowledgeLoss']);

        $orphaned = 0;

        if ($hasPublic) {
            if (! $acknowledged) {
                throw new ValidationException([
                    'acknowledgeLoss' => $this->translator->trans('ramon-verified.api.encryption.acknowledge_loss_required'),
                ]);
            }

            $this->cipher->forgetPublicKey();
            $orphaned = $this->purgeOrphanedDocuments();
        }

        $pair = $this->cipher->generateKeypair();

        $this->logger->warning('verified: encryption keypair regenerated', [
            'actor_id'           => (int) $actor->id,
            'actor_username'     => (string) $actor->username,
            'orphaned_documents' => $orphaned,
            'rotation'           => $hasPublic,
        ]);

        return (new JsonResponse([
            'publicKey'         => $pair['public'],
            'privateKey'        => $pair['private'],
            'configKey'         => DocumentCipher::CONFIG_PRIVATE_KEY,
            'orphanedDocuments' => $orphaned,
        ], 200))
            ->withHeader('Cache-Control', 'no-store, max-age=0, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Clear-Site-Data', '"cache"');
    }

    /**
     * Apaga arquivos cifrados pela chave antiga. Ordem
     * (forget→purge→generate) garante que uploads concorrentes caiam no
     * path plaintext durante a janela.
     */
    private function purgeOrphanedDocuments(): int
    {
        $disk = $this->filesystem->disk(DocumentPathResolver::DISK);
        $purged = 0;

        VerificationRequest::query()
            ->whereNotNull('document_path')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($disk, &$purged) {
                foreach ($rows as $row) {
                    $relative = $this->resolver->resolveRelative(
                        (string) $row->document_path,
                        (int) $row->user_id
                    );
                    if ($relative === null || ! $disk->exists($relative)) {
                        continue;
                    }

                    $blob = $disk->get($relative);
                    if (! is_string($blob) || ! DocumentCipher::isEncryptedBlob($blob)) {
                        continue;
                    }

                    try {
                        $deleted = $disk->delete($relative);
                    } catch (Throwable $e) {
                        $deleted = false;
                    }

                    if ($deleted || ! $disk->exists($relative)) {
                        $row->document_path = null;
                        $row->save();
                        $purged++;
                    } else {
                        $this->logger->warning('verified: keypair regenerate failed to unlink encrypted document', [
                            'request_id' => (int) $row->id,
                            'user_id'    => (int) $row->user_id,
                        ]);
                    }
                }
            });

        return $purged;
    }
}
