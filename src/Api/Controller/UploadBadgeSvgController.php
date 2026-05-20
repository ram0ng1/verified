<?php

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

/**
 * Armazena o SVG customizado do badge no disco `flarum-assets`. O arquivo
 * passa por SvgSanitizer antes de gravar: script, foreignObject, event
 * handlers e URLs `javascript:` / `data:` são removidos. Se o SVG sanitizado
 * for pequeno o suficiente, o conteúdo vai também para a setting
 * `ramon-verified.badge_svg_content` para inlining sem fetch.
 */
class UploadBadgeSvgController extends UploadImageController
{
    protected string $filePathSettingKey = 'ramon-verified.badge_svg_path';

    protected string $filenamePrefix = 'verified-badge';

    protected string $fileExtension = 'svg';

    public const MAX_SVG_BYTES = 256 * 1024;

    /**
     * Acima deste tamanho de SVG sanitizado o `extend.php` deixa de
     * inlinear `ramonVerifiedBadgeSvgContent` no payload do forum; o
     * frontend recai para fetch via `ramonVerifiedBadgeSvgPath`. Selos
     * razoáveis ficam bem abaixo de 8 KB.
     */
    public const INLINE_SVG_THRESHOLD = 8 * 1024;

    /**
     * Allowlist de extensão e MIME (cliente + server-side) ANTES do parent
     * chamar `makeImage()`. Sem essas barreiras, um arquivo `.png` com
     * Content-Type forjado passa direto até o DOMDocument no sanitizer,
     * onde falha — mas com 500 em vez de 422, e depois de ter sido
     * carregado em memória.
     */
    private const ALLOWED_EXTENSIONS = ['svg'];

    private const ALLOWED_MIMES = [
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
     * Allowlist em duas camadas: cliente-MIME (defesa rápida contra
     * ferramentas honestas) + detecção server-side via `finfo`/
     * `mime_content_type` (defesa real contra polyglot e Content-Type
     * forjado). Quando o cliente OMITE o Content-Type E a detecção
     * server-side também falha (temp file ilegível, finfo ausente),
     * o upload é recusado — falhar fechado em vez de aceitar cego,
     * mesmo padrão do `UploadDocumentController::validateMimeTypes` (audit F5).
     */
    private function validateMime(UploadedFileInterface $file): void
    {
        $clientMime = strtolower((string) $file->getClientMediaType());
        if ($clientMime === '' || ! in_array($clientMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.bad_mime'),
            ]);
        }

        $detected = $this->detectServerMime($file);
        if ($detected === null) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.upload_failed'),
            ]);
        }

        if (! in_array(strtolower($detected), self::ALLOWED_MIMES, true)) {
            throw new ValidationException([
                'badge_svg' => $this->translator->trans('ramon-verified.api.badge_svg.bad_mime'),
            ]);
        }
    }

    private function detectServerMime(UploadedFileInterface $file): ?string
    {
        $tmpPath = $file->getStream()->getMetadata('uri');
        if (! is_string($tmpPath) || ! is_readable($tmpPath)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                return is_string($detected) && $detected !== '' ? $detected : null;
            }
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($tmpPath);
            return is_string($detected) && $detected !== '' ? $detected : null;
        }

        return null;
    }
}
