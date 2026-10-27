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

use Flarum\Api\Controller\UploadImageController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Ramon\Verified\Support\SvgSanitizer;
use Ramon\Verified\Support\UploadedFileMime;

/**
 * Todo SVG passa pelo SvgSanitizer antes de ser gravado em `flarum-assets`. Se
 * for pequeno, também vai para `ramon-verified.badge_svg_content` e é inlinado
 * no payload do fórum.
 */
class UploadBadgeSvgController extends UploadImageController
{
    protected string $filePathSettingKey = 'ramon-verified.badge_svg_path';

    protected string $filenamePrefix = 'verified-badge';

    protected string $fileExtension = 'svg';

    public const MAX_SVG_BYTES = 256 * 1024;

    /**
     * Acima disso o SVG não é inlinado e o frontend faz fetch pelo path.
     */
    public const INLINE_SVG_THRESHOLD = 8 * 1024;

    /**
     * Validado antes de o parent chamar `makeImage()`: senão um `.png` com
     * Content-Type forjado chega ao DOMDocument e falha com 500 em vez de 422.
     */
    private const ALLOWED_EXTENSIONS = ['svg'];

    /**
     * O que um cliente honesto envia para `.svg`. Estrito de propósito: nenhum
     * navegador rotula SVG como `text/xml` ou `text/plain`.
     */
    private const ALLOWED_CLIENT_MIMES = [
        'image/svg+xml',
        'image/svg',
    ];

    /**
     * O libmagic varia: sem prólogo `<?xml?>` um SVG costuma sair como `text/xml`,
     * `application/xml` ou `text/plain`. A lista é permissiva porque o gate real é
     * o `SvgSanitizer`, que exige `<svg>` como raiz.
     */
    private const ALLOWED_DETECTED_MIMES = [
        'image/svg+xml',
        'image/svg',
        'text/xml',
        'application/xml',
        'text/plain',
    ];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $file = $request->getUploadedFiles()[$this->filenamePrefix] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.missing'),
            ]);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.upload_failed'),
            ]);
        }

        $this->validateExtension($file);
        $this->validateMime($file);

        return parent::handle($request);
    }

    #[\Override]
    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface|StreamInterface
    {
        $reportedSize = $file->getSize();
        if ($reportedSize !== null && $reportedSize > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.too_large'),
            ]);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->read(self::MAX_SVG_BYTES + 1);
        if (strlen($content) > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.too_large'),
            ]);
        }

        $sanitized = SvgSanitizer::sanitize($content);

        $this->settings->set('ramon-verified.badge_svg_content', $sanitized);

        $resource = fopen('php://temp', 'r+');
        if (! is_resource($resource)) {
            throw new \RuntimeException('Failed to allocate temp stream for badge SVG.');
        }
        fwrite($resource, $sanitized);
        rewind($resource);

        return new Stream($resource);
    }

    private function validateExtension(UploadedFileInterface $file): void
    {
        $extension = strtolower((string) pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.bad_extension'),
            ]);
        }
    }

    /**
     * Se o cliente omite o Content-Type e a detecção no servidor também falha, o
     * upload é recusado (fail-closed).
     */
    private function validateMime(UploadedFileInterface $file): void
    {
        $clientMime = strtolower((string) $file->getClientMediaType()); /* finfo-gated: allowlist de fachada, o gate real é a detecção fail-closed logo abaixo */
        if ($clientMime === '' || ! in_array($clientMime, self::ALLOWED_CLIENT_MIMES, true)) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.bad_mime'),
            ]);
        }

        $detected = UploadedFileMime::detect($file);
        if ($detected === null) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.upload_failed'),
            ]);
        }

        if (! in_array(strtolower($detected), self::ALLOWED_DETECTED_MIMES, true)) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.bad_mime'),
            ]);
        }
    }

}
