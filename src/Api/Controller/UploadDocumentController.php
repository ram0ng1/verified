<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\Paths;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Receives a verification document upload, stores it OUTSIDE the public webroot,
 * and returns an opaque token the user can attach to a verification request.
 *
 * The token format is: verified-documents/{userId}/{filename}. It is only resolved
 * on the server side by the document-download endpoint, never served as static.
 */
class UploadDocumentController implements RequestHandlerInterface
{
    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'pdf'];

    public const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        protected Paths $paths,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected DocumentCipher $cipher,
        protected DocumentRetention $retention
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('verified.request');

        // Admin kill-switch on the upload path too — refuse to store any
        // document when intake is globally closed.
        if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.requests_closed'),
            ]);
        }

        if ((bool) $actor->is_verified) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_verified'),
            ]);
        }

        $existing = VerificationRequest::query()
            ->where('user_id', $actor->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.already_pending'),
            ]);
        }

        $files = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $files['document'] ?? null;

        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.no_file'),
            ]);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.upload_failed'),
            ]);
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0 || $size > self::MAX_BYTES) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.file_too_large'),
            ]);
        }

        $originalName = (string) $file->getClientFilename();
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_extension'),
            ]);
        }

        $clientMime = strtolower((string) $file->getClientMediaType());
        if ($clientMime && ! in_array($clientMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_mime'),
            ]);
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $detectedMime = null;

        if (is_string($tmpPath) && is_readable($tmpPath) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $tmpPath) ?: null;
                finfo_close($finfo);
            }
        }

        if ($detectedMime && ! in_array(strtolower($detectedMime), self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_mime'),
            ]);
        }

        $userId = (int) $actor->id;
        $dir = rtrim($this->paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents'.DIRECTORY_SEPARATOR.$userId;

        if (! is_dir($dir)) {
            if (! mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new ValidationException([
                    'document' => $this->translator->trans('ramon-verified.api.upload_failed'),
                ]);
            }
        }

        // Sweep any of this user's old documents that aren't referenced by
        // a live (pending or approved) request row before storing the new
        // one. This shuts down the disk-fill loop where an attacker could
        // upload-submit-delete repeatedly to leak storage.
        $this->retention->sweepOrphans($userId);

        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $dest = $dir.DIRECTORY_SEPARATOR.$filename;

        if ($this->cipher->canEncrypt()) {
            // Read the upload into memory once, encrypt, write the
            // sealed-box payload to disk. The 8 MB MAX_BYTES cap above
            // means worst case is ~8 MB resident; well within reason.
            $stream = $file->getStream();
            $stream->rewind();
            $plaintext = $stream->getContents();

            $encrypted = $this->cipher->encrypt($plaintext);

            // Best-effort scrub — sodium doesn't do anything special with
            // PHP strings, but explicit zeroing reduces the window during
            // which the plaintext is in memory.
            sodium_memzero($plaintext);

            if (file_put_contents($dest, $encrypted, LOCK_EX) === false) {
                throw new ValidationException([
                    'document' => $this->translator->trans('ramon-verified.api.upload_failed'),
                ]);
            }
        } else {
            $file->moveTo($dest);
        }

        @chmod($dest, 0640);

        // Token is a relative-path opaque identifier. It is never resolved client-side.
        $token = 'verified-documents/'.$userId.'/'.$filename;

        // Front-end uses /assets/verified/{userId}/{filename} as a virtual path that
        // the resource validates against the actor's id. The actual disk location is
        // hidden behind a server-side download endpoint.
        $publicToken = '/assets/verified/'.$userId.'/'.$filename;

        return new JsonResponse([
            'documentPath' => $publicToken,
            'token'        => $token,
        ], 200);
    }
}
