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
use Ramon\Verified\Models\VerificationRequest;
use RuntimeException;

class DownloadDocumentController implements RequestHandlerInterface
{
    public function __construct(
        protected Paths $paths,
        protected DocumentCipher $cipher,
        protected TranslatorInterface $translator
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

        $id = (int) ($request->getQueryParams()['id'] ?? 0);
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

        $relative = $this->resolveTokenToRelative((string) $req->document_path, (int) $req->user_id);
        if ($relative === null) {
            throw new RouteNotFoundException();
        }

        $base = realpath(rtrim($this->paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents');
        if ($base === false) {
            throw new RouteNotFoundException();
        }

        $absolute = realpath($base.DIRECTORY_SEPARATOR.$relative);
        if ($absolute === false || ! is_file($absolute) || ! str_starts_with($absolute, $base.DIRECTORY_SEPARATOR)) {
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

            $plaintext = $this->cipher->decrypt($blob);

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

        return (new Response())
            ->withBody($stream)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) $contentLength)
            ->withHeader('Content-Disposition', $disposition.'; filename="document.'.$extension.'"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * Convert a stored document_path (e.g. "/assets/verified/123/abc.pdf") into the
     * storage-relative path "123/abc.pdf". Rejects malicious input.
     */
    protected function resolveTokenToRelative(string $token, int $expectedUserId): ?string
    {
        $token = ltrim($token, '/');

        if (str_contains($token, '..') || str_contains($token, "\0")) {
            return null;
        }

        $prefix = 'assets/verified/';
        if (! str_starts_with($token, $prefix)) {
            return null;
        }

        $rest = substr($token, strlen($prefix));
        $parts = explode('/', $rest);

        if (count($parts) !== 2) {
            return null;
        }

        [$userIdPart, $filename] = $parts;

        if ((int) $userIdPart !== $expectedUserId) {
            return null;
        }

        if (! preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)$/i', $filename)) {
            return null;
        }

        return $userIdPart.'/'.$filename;
    }
}
