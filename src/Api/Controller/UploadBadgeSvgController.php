<?php

namespace Ramon\Verified\Api\Controller;

use Flarum\Api\Controller\UploadImageController;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Stores the admin's custom verified-badge SVG in the flarum-assets disk
 * (same approach as avocado's logo SVG). The file is sanitised before
 * being written: scripts, foreign objects, event handlers and unsafe URLs
 * are stripped.
 */
class UploadBadgeSvgController extends UploadImageController
{
    protected string $filePathSettingKey = 'ramon-verified.badge_svg_path';

    protected string $filenamePrefix = 'verified-badge';

    protected string $fileExtension = 'svg';

    #[\Override]
    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface|StreamInterface
    {
        if ($file->getSize() !== null && $file->getSize() > 256 * 1024) {
            throw new ValidationException([
                'badge_svg' => 'SVG file is too large (max 256 KB).',
            ]);
        }

        $sanitized = $this->sanitizeSvg((string) $file->getStream());

        // Persist the sanitised SVG content directly as a setting so the
        // forum frontend can render it inline on every page load — no fetch,
        // no race condition, no chance of "reverts to default" on reload.
        // The file on disk is kept too (so the admin's UploadImageButton
        // shows a preview thumbnail of the uploaded asset).
        $this->settings->set('ramon-verified.badge_svg_content', $sanitized);

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $sanitized);
        rewind($resource);

        return new Stream($resource);
    }

    private function sanitizeSvg(string $content): string
    {
        $prev = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        if (! $dom->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) {
            libxml_use_internal_errors($prev);
            throw new ValidationException([
                'badge_svg' => 'Invalid SVG: could not parse XML.',
            ]);
        }

        libxml_use_internal_errors($prev);

        $root = $dom->documentElement;
        if (! $root || strtolower($root->localName) !== 'svg') {
            throw new ValidationException([
                'badge_svg' => 'The uploaded file must be a valid SVG.',
            ]);
        }

        $this->cleanNode($root);
        $this->replaceFillsWithCurrentColor($root);

        return (string) $dom->saveXML($root);
    }

    /**
     * Walks the SVG and rewrites every `fill` attribute (other than `none`,
     * `transparent`, or a whiteish value) to `currentColor`. This means the
     * admin's badge_color setting (or the forum's primary colour) drives the
     * visible colour of the uploaded artwork — while preserving any explicit
     * WHITE inner shapes (typically the inner check on a verified-style
     * seal), so the "middle white" stays white on dark backgrounds.
     */
    private function replaceFillsWithCurrentColor(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement) {
            if ($node->hasAttribute('fill')) {
                $current = strtolower(trim($node->getAttribute('fill')));
                $skip = $current === ''
                    || $current === 'none'
                    || $current === 'transparent'
                    || $this->isWhiteFill($current);
                if (! $skip) {
                    $node->setAttribute('fill', 'currentColor');
                }
            }

            // Inline `style` rules can also set fill — strip those so they
            // don't override `currentColor` on the same element.
            if ($node->hasAttribute('style')) {
                $style = $node->getAttribute('style');
                $cleanedStyle = preg_replace(
                    '/\s*fill\s*:\s*[^;]+;?/i',
                    '',
                    $style
                );
                $cleanedStyle = trim((string) $cleanedStyle, " \t\n\r;");
                if ($cleanedStyle === '') {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', $cleanedStyle);
                }
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->replaceFillsWithCurrentColor($child);
        }
    }

    /**
     * Recognises common ways an SVG can express "white" — preserved so the
     * inner check of a verified-style seal stays white instead of being
     * recoloured to currentColor.
     */
    private function isWhiteFill(string $value): bool
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

    private function cleanNode(\DOMNode $node): void
    {
        static $dangerous = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'base', 'link', 'style'];

        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->localName), $dangerous, true)) {
                    $node->removeChild($child);
                    continue;
                }
                $this->cleanNode($child);
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
            }
        }

        foreach ($remove as $attrName) {
            $node->removeAttribute($attrName);
        }
    }
}
