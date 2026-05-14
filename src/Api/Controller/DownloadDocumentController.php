<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\Paths;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\VerificationRequest;
use RuntimeException;

class DownloadDocumentController implements RequestHandlerInterface
{
    public function __construct(
        protected Paths $paths,
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator,
        protected DocumentPathResolver $resolver
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // Documents (RG / CPF / passport, etc.) are sensitive identity data —
        // only administrators can read them. The submitter has the original
        // file on their own machine; they don't need to fetch it back.
        if (! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }

        // Route param arrives as a request attribute; the legacy query-bag
        // merge still works as a fallback for callers that thread the id
        // explicitly. Either way, treat 0/missing as 404 — never differentiate
        // "not found" from "denied" so the response shape doesn't leak which
        // ids are valid.
        $rawId = $request->getAttribute('id') ?? ($request->getQueryParams()['id'] ?? 0);
        $id    = (int) $rawId;
        if ($id <= 0) {
            throw new RouteNotFoundException();
        }

        /** @var VerificationRequest|null $req */
        $req = VerificationRequest::query()->find($id);
        if (! $req) {
            throw new RouteNotFoundException();
        }

        if (! $req->document_path) {
            throw new RouteNotFoundException();
        }

        $absolute = $this->resolver->resolveAbsolute((string) $req->document_path, (int) $req->user_id);
        if ($absolute === null || ! is_file($absolute)) {
            throw new RouteNotFoundException();
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            default => 'application/octet-stream',
        };

        $isEncrypted = DocumentCipher::isEncryptedFile($absolute);

        // Encrypted files require the private key to be present in
        // config.php. If it's missing we surface a clear error rather than
        // a generic 404 — the file IS there, it just can't be unsealed.
        if ($isEncrypted && ! $this->cipher->canDecrypt()) {
            throw new RuntimeException(
                (string) $this->translator->trans('ramon-verified.api.encryption.private_key_missing')
            );
        }

        if ($isEncrypted) {
            $blob = file_get_contents($absolute);
            if ($blob === false) {
                throw new RouteNotFoundException();
            }

            $plaintext = $this->cipher->decryptIfEncrypted($blob);

            $stream = new Stream('php://temp', 'r+');
            $stream->write($plaintext);
            $stream->rewind();

            $contentLength = strlen($plaintext);

            // Best-effort wipe of the in-memory plaintext after handing
            // a copy to the response stream.
            sodium_memzero($plaintext);
        } else {
            $stream = new Stream(fopen($absolute, 'rb'));
            $contentLength = filesize($absolute);
        }

        $disposition = $request->getQueryParams()['download'] ?? null
            ? 'attachment'
            : 'inline';

        $response = (new Response())
            ->withBody($stream)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) $contentLength)
            ->withHeader('Content-Disposition', $disposition.'; filename="document.'.$extension.'"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0');

        // PDFs viewed inline can carry embedded scripts and form actions.
        // CSP `sandbox` neutralises those without breaking the built-in
        // browser PDF viewer (which renders fine inside a sandboxed frame).
        if ($mime === 'application/pdf') {
            $response = $response->withHeader('Content-Security-Policy', "sandbox");
        }

        return $response;
    }
}
