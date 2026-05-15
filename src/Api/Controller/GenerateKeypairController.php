<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
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
        protected Paths $paths,
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
            // A public key already exists — whether the system is healthy
            // (rotation) or broken (recovery), generating a new one
            // invalidates every previously encrypted document. The admin
            // must say so explicitly.
            if (! $acknowledged) {
                throw new ValidationException([
                    'acknowledgeLoss' => $this->translator->trans('ramon-verified.api.encryption.acknowledge_loss_required'),
                ]);
            }

            // ORDER MATTERS (audit N7). Forget the public key FIRST so the
            // race window between "we're about to purge" and "the new key
            // is in place" cannot have a concurrent upload encrypt a fresh
            // file to the OLD pubkey — that file would not be in our purge
            // chunk and would persist as an undecryptable orphan. With the
            // key gone, concurrent uploads fall through to the plaintext
            // path, which the new keypair handles transparently via
            // `decryptIfEncrypted`'s magic-header branch.
            $this->cipher->forgetPublicKey();

            // Now safe to walk the request rows: no new encrypted files
            // can appear after this point.
            $orphaned = $this->purgeOrphanedDocuments();
        }

        $pair = $this->cipher->generateKeypair();

        // Total-loss audit trail: regeneration is a destructive,
        // single-request action with no second-factor confirmation. Make
        // the action discoverable in `storage/logs/flarum.log` (mirroring
        // CLAUDE.md §23) so ops can trace who triggered a wipe — without
        // ever logging the private key itself.
        $this->logger->warning('verified: encryption keypair regenerated', [
            'actor_id'           => (int) $actor->id,
            'actor_username'     => (string) $actor->username,
            'orphaned_documents' => $orphaned,
            'rotation'           => $hasPublic,
        ]);

        // CRITICAL: this is the ONLY time the freshly generated private key
        // is ever rendered. The response body MUST NOT be cached by any
        // intermediate proxy, browser disk cache, or BFCache — losing this
        // response means losing the ability to decrypt every document the
        // forum encrypts to the matching public key (audit finding F1).
        //
        // Header roles (audit N12 corrected the original attribution):
        //   - `Cache-Control: no-store` is the LOAD-BEARING header. Modern
        //     Chromium/Firefox treat any response with `no-store` on a
        //     navigation as BFCache-disqualifying; this is what actually
        //     prevents the "press back, see the key again" attack.
        //   - `Pragma: no-cache` + `Expires: 0` cover legacy HTTP/1.0
        //     intermediate proxies that ignore `Cache-Control`.
        //   - `Clear-Site-Data: "cache"` is additional defence — it
        //     instructs the user agent to evict HTTP cache entries for the
        //     origin AND (per spec) the BFCache entry for the current
        //     top-level browsing context. Don't remove either: the
        //     no-store header is what's mandatory; the others harden.
        return (new JsonResponse([
            'publicKey'        => $pair['public'],
            'privateKey'       => $pair['private'],
            'configKey'        => DocumentCipher::CONFIG_PRIVATE_KEY,
            'orphanedDocuments' => $orphaned,
        ], 200))
            ->withHeader('Cache-Control', 'no-store, max-age=0, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('Clear-Site-Data', '"cache"');
    }

    /**
     * Walk every verification request that points at an encrypted file
     * and unlink it. Returns the count purged so the admin UI can surface
     * "we wiped N documents". Plaintext (legacy) files are left alone —
     * they were never encrypted by us and are still readable.
     */
    private function purgeOrphanedDocuments(): int
    {
        if ($this->resolver->baseDirectory() === null) {
            // No documents directory — nothing to purge, but also nothing
            // is broken; let the regeneration proceed.
            return 0;
        }

        $purged = 0;

        VerificationRequest::query()
            ->whereNotNull('document_path')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$purged) {
                foreach ($rows as $row) {
                    $absolute = $this->resolver->resolveAbsolute((string) $row->document_path, (int) $row->user_id);
                    if ($absolute === null || ! DocumentCipher::isEncryptedFile($absolute)) {
                        continue;
                    }

                    // Order matters (audit L6): only null the row when
                    // the file is actually gone. The previous shape
                    // (`@unlink; save`) lost both signals — a failed
                    // unlink (Windows file-lock, permission denied, race
                    // with another worker) still persisted `null` to the
                    // DB, leaving an orphan file with no pointer back.
                    // After this fix, a failed unlink leaves the row
                    // pointing at the still-present file so the next
                    // sweep can retry, and the failure is logged for
                    // ops visibility (CLAUDE.md §23).
                    $unlinked = @unlink($absolute);
                    if ($unlinked || ! is_file($absolute)) {
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
