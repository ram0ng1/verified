<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\Support\UploadedFileMime;
use Ramon\Verified\VerifiedStatus;

/**
 * Recebe o upload de um documento de verificação, grava no disco privado
 * `flarum-verified-documents` (fora do webroot público) e devolve o
 * pseudo-path `/assets/verified/{userId}/{filename}` que o usuário anexa
 * à solicitação. A resolução real para o disco privado acontece no
 * endpoint de download, via DocumentPathResolver.
 */
class UploadDocumentController implements RequestHandlerInterface
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'pdf'];

    public const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        protected Factory $filesystem,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected DocumentCipher $cipher,
        protected DocumentRetention $retention,
        protected VerifiedStatus $verifiedStatus
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('verified.request');

        if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
            throw new ValidationException([
                'status' => $this->translator->trans('ramon-verified.api.requests_closed'),
            ]);
        }

        if ($this->verifiedStatus->isVerified($actor)) {
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

        $file = $this->extractUploadedFile($request);
        $extension = $this->validateAndDetectExtension($file);
        $this->validateMimeTypes($file);

        $userId = (int) $actor->id;
        $disk = $this->filesystem->disk(DocumentPathResolver::DISK);

        $this->retention->sweepOrphans($userId);

        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        $relative = $userId.'/'.$filename;

        $stream = $file->getStream();
        $stream->rewind();
        $plaintext = $stream->getContents();

        if ($this->cipher->canEncrypt()) {
            $payload = $this->cipher->encrypt($plaintext);
            sodium_memzero($plaintext);
        } else {
            $payload = $plaintext;
        }

        if (! $disk->put($relative, $payload)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.upload_failed'),
            ]);
        }

        return new JsonResponse([
            'documentPath' => '/assets/verified/'.$userId.'/'.$filename,
        ], 200);
    }

    private function extractUploadedFile(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
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

        return $file;
    }

    private function validateAndDetectExtension(UploadedFileInterface $file): string
    {
        $originalName = (string) $file->getClientFilename();
        $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_extension'),
            ]);
        }

        return $extension;
    }

    /**
     * Allowlist em duas camadas: cliente-MIME (defesa rápida contra ferramentas
     * honestas) + detecção server-side via `finfo`/`mime_content_type` (defesa
     * real contra polyglot / Content-Type forjado). Audit F5 corrigiu o bypass:
     * quando o temp file não é legível (NFS lenta, FS read-only, file handle
     * preso em Windows), a detecção retorna null e o fluxo antigo passava
     * direto. Agora `null` falha o upload, independentemente do motivo.
     */
    private function validateMimeTypes(UploadedFileInterface $file): void
    {
        $clientMime = strtolower((string) $file->getClientMediaType()); /* finfo-gated: allowlist de fachada, o gate real é a detecção fail-closed logo abaixo */
        if ($clientMime === '' || ! in_array($clientMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_mime'),
            ]);
        }

        $detectedMime = UploadedFileMime::detect($file);

        if ($detectedMime === null) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.upload_failed'),
            ]);
        }

        if (! in_array(strtolower($detectedMime), self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'document' => $this->translator->trans('ramon-verified.api.bad_mime'),
            ]);
        }
    }

}
