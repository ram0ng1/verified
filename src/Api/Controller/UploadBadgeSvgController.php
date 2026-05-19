<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Api\Controller\UploadImageController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Armazena o SVG customizado do badge no disco `flarum-assets`. O arquivo
 * é sanitizado antes de gravar: script, foreignObject, event handlers e
 * URLs `javascript:` / `data:` são removidos. Se o SVG sanitizado for
 * pequeno o suficiente, o conteúdo vai também para a setting
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
     * Garante que o multipart traga o campo esperado ANTES do parent
     * chamar `makeImage()` — sem isso, request sem arquivo virava
     * TypeError → 500 em vez de 422.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $file = $request->getUploadedFiles()[$this->filenamePrefix] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException([
                'badge_svg' => 'Missing SVG file in the "verified-badge" field.',
            ]);
        }

        return parent::handle($request);
    }

    #[\Override]
    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface|StreamInterface
    {
        $reportedSize = $file->getSize();
        if ($reportedSize !== null && $reportedSize > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => 'SVG file is too large (max 256 KB).',
            ]);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->read(self::MAX_SVG_BYTES + 1);
        if (strlen($content) > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => 'SVG file is too large (max 256 KB).',
            ]);
        }

        $sanitized = self::sanitizeSvg($content);

        $this->settings->set('ramon-verified.badge_svg_content', $sanitized);

        $resource = fopen('php://temp', 'r+');
        if (! is_resource($resource)) {
            throw new \RuntimeException('Failed to allocate temp stream for badge SVG.');
        }
        fwrite($resource, $sanitized);
        rewind($resource);

        return new Stream($resource);
    }

    /**
     * Sanitiza um SVG. Devolve string vazia em entrada não-parseável;
     * `throwOnInvalid=true` lança ValidationException em vez disso.
     */
    public static function sanitizeSvg(string $content, bool $throwOnInvalid = true): string
    {
        if ($content === '') return '';

        $content = preg_replace('/<!DOCTYPE\b[^>\[]*(?:\[[\s\S]*?\])?\s*>/i', '', $content);
        $content = preg_replace('/<!ENTITY\b[\s\S]*?>/i', '', $content);
        $content = ltrim((string) $content);

        if ($content === '') {
            if ($throwOnInvalid) {
                throw new ValidationException([
                    'badge_svg' => 'Invalid SVG: empty after stripping DOCTYPE/ENTITY.',
                ]);
            }
            return '';
        }

        $prev = libxml_use_internal_errors(true);

        try {
            $dom = new \DOMDocument();
            if (! $dom->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) {
                if ($throwOnInvalid) {
                    throw new ValidationException([
                        'badge_svg' => 'Invalid SVG: could not parse XML.',
                    ]);
                }
                return '';
            }

            $root = $dom->documentElement;
            if (! $root || strtolower($root->localName) !== 'svg') {
                if ($throwOnInvalid) {
                    throw new ValidationException([
                        'badge_svg' => 'The uploaded file must be a valid SVG.',
                    ]);
                }
                return '';
            }

            self::cleanNode($root);
            self::replaceFillsWithCurrentColor($root);

            return (string) $dom->saveXML($root);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Reescreve cada atributo `fill` (exceto `none`, `transparent` ou
     * branco-ish) para `currentColor`. Resultado: a cor do tier (ou a
     * `@primary-color` do fórum) pilota a aparência do selo, preservando
     * shapes brancos internos (típicos do check central).
     */
    private static function replaceFillsWithCurrentColor(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement) {
            if ($node->hasAttribute('fill')) {
                $current = strtolower(trim($node->getAttribute('fill')));
                $skip = $current === ''
                    || $current === 'none'
                    || $current === 'transparent'
                    || self::isWhiteFill($current);
                if (! $skip) {
                    $node->setAttribute('fill', 'currentColor');
                }
            }

            if ($node->hasAttribute('style')) {
                $style = $node->getAttribute('style');
                $cleanedStyle = preg_replace('/\s*fill\s*:\s*[^;]+;?/i', '', $style);
                $cleanedStyle = trim((string) $cleanedStyle, " \t\n\r;");
                if ($cleanedStyle === '') {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', $cleanedStyle);
                }
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            self::replaceFillsWithCurrentColor($child);
        }
    }

    private static function isWhiteFill(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === 'white' || $value === '#fff' || $value === '#ffffff') {
            return true;
        }
        if (preg_match('/^rgba?\(\s*255\s*,\s*255\s*,\s*255(\s*,\s*[0-9.]+)?\s*\)$/', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Remove tags ativas e atributos perigosos. `<a>` é stripado para matar
     * phishing-via-badge; `animate*` é stripado porque SMIL pode reescrever
     * `xlink:href` em tempo de execução, contornando o scrub estático.
     */
    private static function cleanNode(\DOMNode $node): void
    {
        static $dangerous = [
            'script', 'foreignobject', 'iframe', 'object', 'embed', 'base', 'link', 'style',
            'a', 'animate', 'animatetransform', 'animatemotion', 'set',
        ];

        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->localName), $dangerous, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::cleanNode($child);
            } elseif ($child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }

        if (! ($node instanceof \DOMElement)) {
            return;
        }

        $remove = [];

        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            $val  = ltrim($attr->value);

            if (str_starts_with($name, 'on')) {
                $remove[] = $attr->name;
                continue;
            }

            if (preg_match('/^javascript\s*:/i', $val)) {
                $remove[] = $attr->name;
                continue;
            }

            if (in_array($name, ['href', 'xlink:href', 'src', 'action'], true)
                && preg_match('/^data\s*:/i', $val)) {
                $remove[] = $attr->name;
                continue;
            }

            if (in_array($name, ['href', 'xlink:href'], true)
                && preg_match('#^(https?:)?//#i', $val)) {
                $remove[] = $attr->name;
            }
        }

        foreach ($remove as $attrName) {
            $node->removeAttribute($attrName);
        }
    }
}
