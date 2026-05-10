<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Generates an encryption keypair. The new public key is persisted as a
 * setting; the private key is returned in the response body ONCE, so the
 * admin can paste it into config.php manually. Subsequent calls will not
 * re-emit the same private key — if it's lost, the only recovery path is
 * to regenerate, which destroys all previously-encrypted documents.
 *
 * Flow:
 *   - Initial setup (no public key yet): generate freely.
 *   - Healthy state (public key set + private present in config.php):
 *     allow rotation when the caller acknowledges that all existing
 *     encrypted documents will be wiped (`acknowledgeLoss=true`). The
 *     admin uses this to remove the current public key and replace it
 *     with a fresh pair.
 *   - Broken state (public key set but private MISSING from config.php):
 *     same shape as rotation — `acknowledgeLoss=true` required, all
 *     encrypted files unlinked, `document_path` cleared.
 *
 * In short: if there is a current public key, we always purge before
 * issuing a new one, and the admin must say so explicitly.
 */
class GenerateKeypairController implements RequestHandlerInterface
{
    public function __construct(
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator,
        protected Paths $paths
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
            // A public key already exists — whether the system is healthy
            // (rotation) or broken (recovery), generating a new one
            // invalidates every previously encrypted document. The admin
            // must say so explicitly.
            if (! $acknowledged) {
                throw new ValidationException([
                    'acknowledgeLoss' => $this->translator->trans('ramon-verified.api.encryption.acknowledge_loss_required'),
                ]);
            }

            // Unlink every encrypted file currently on disk and null its
            // `document_path` so the audit trail stays consistent. The
            // forgetPublicKey() call below makes the situation explicit
            // even if generateKeypair() were to fail mid-flight.
            $orphaned = $this->purgeOrphanedDocuments();
            $this->cipher->forgetPublicKey();
        }

        $pair = $this->cipher->generateKeypair();

        return new JsonResponse([
            'publicKey'        => $pair['public'],
            'privateKey'       => $pair['private'],
            'configKey'        => DocumentCipher::CONFIG_PRIVATE_KEY,
            'orphanedDocuments' => $orphaned,
        ], 200);
    }

    /**
     * Walk every verification request that points at an encrypted file
     * and unlink it. Returns the count purged so the admin UI can surface
     * "we wiped N documents". Plaintext (legacy) files are left alone —
     * they were never encrypted by us and are still readable.
     */
    private function purgeOrphanedDocuments(): int
    {
        $base = realpath(rtrim($this->paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents');
        if ($base === false) {
            // No documents directory — nothing to purge, but also nothing
            // is broken; let the regeneration proceed.
            return 0;
        }

        $purged = 0;

        VerificationRequest::query()
            ->whereNotNull('document_path')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($base, &$purged) {
                foreach ($rows as $row) {
                    $absolute = $this->resolveAbsolutePath((string) $row->document_path, (int) $row->user_id, $base);
                    if ($absolute !== null && DocumentCipher::isEncryptedFile($absolute)) {
                        @unlink($absolute);
                        $row->document_path = null;
                        $row->save();
                        $purged++;
                    }
                }
            });

        return $purged;
    }

    private function resolveAbsolutePath(string $token, int $expectedUserId, string $base): ?string
    {
        $token = ltrim($token, '/');

        if (str_contains($token, '..') || str_contains($token, "\0")) return null;

        $prefix = 'assets/verified/';
        if (! str_starts_with($token, $prefix)) return null;

        $rest  = substr($token, strlen($prefix));
        $parts = explode('/', $rest);
        if (count($parts) !== 2) return null;

        [$userIdPart, $filename] = $parts;
        if ((int) $userIdPart !== $expectedUserId) return null;

        if (! preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)$/i', $filename)) return null;

        $candidate = $base.DIRECTORY_SEPARATOR.$userIdPart.DIRECTORY_SEPARATOR.$filename;
        $absolute  = realpath($candidate);

        if ($absolute === false || ! str_starts_with($absolute, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $absolute;
    }
}
