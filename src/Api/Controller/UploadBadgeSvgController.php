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

    public const MAX_SVG_BYTES = 256 * 1024;

    /**
     * Above this many bytes of SANITISED SVG, `extend.php` strips the
     * `ramonVerifiedBadgeSvgContent` forum attribute and the frontend
     * falls back to fetching the file at `ramonVerifiedBadgeSvgPath`
     * over HTTP (with whatever static-asset caching the host serves it
     * under). Below the threshold the SVG is inlined into the forum
     * payload so badge rendering stays synchronous with no fetch race
     * (typical verified-mark designs sit well under 8 KB; even fancy
     * multi-path seals rarely cross 4 KB).
     *
     * Audit H-SVG (badge content embedded in every forum payload).
     */
    public const INLINE_SVG_THRESHOLD = 8 * 1024;

    #[\Override]
    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface|StreamInterface
    {
        // Reject early when the reported size is over the cap. `getSize()` may
        // return null for chunked uploads — in that case we still cap at read
        // time below so a misreporting client can't OOM the worker.
        $reportedSize = $file->getSize();
        if ($reportedSize !== null && $reportedSize > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => 'SVG file is too large (max 256 KB).',
            ]);
        }

        // Read at most MAX_SVG_BYTES + 1 so we can detect a stream that lied
        // about its length without ever holding more than ~256 KB in memory.
        $stream = $file->getStream();
        $stream->rewind();
        $content = $stream->read(self::MAX_SVG_BYTES + 1);
        if (strlen($content) > self::MAX_SVG_BYTES) {
            throw new ValidationException([
                'badge_svg' => 'SVG file is too large (max 256 KB).',
            ]);
        }

        $sanitized = self::sanitizeSvg($content);

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

    /**
     * Sanitise an SVG string in place. Public + static so the same
     * routine can be called from `extend.php` as a `serializeToForum`
     * cast — defending against the case where unsanitised SVG ends up
     * in the `ramon-verified.badge_svg_content` setting through any
     * non-upload path (DB restore, admin tinkering, external migration).
     *
     * Returns the sanitised SVG, or the empty string when the input
     * isn't a parseable SVG. Callers that want hard rejection (e.g. the
     * upload controller) call `sanitizeSvg($content, throwOnInvalid: true)`.
     */
    public static function sanitizeSvg(string $content, bool $throwOnInvalid = true): string
    {
        if ($content === '') return '';

        // Defense in depth against XXE / billion-laughs. PHP 8+ libxml2
        // already refuses to expand external entities unless `LIBXML_NOENT`
        // is set, and we never pass it. Reject any SVG that even DECLARES
        // a DOCTYPE or ENTITY before parsing — matches the frontend
        // sanitiser, and keeps the door shut even on older libxml2 builds
        // that may behave differently.
        if (preg_match('/<!DOCTYPE/i', $content) || preg_match('/<!ENTITY/i', $content)) {
            if ($throwOnInvalid) {
                throw new ValidationException([
                    'badge_svg' => 'SVG must not contain DOCTYPE or ENTITY declarations.',
                ]);
            }
            return '';
        }

        $prev = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // LIBXML_NONET blocks network entity resolution; we deliberately do
        // NOT pass LIBXML_NOENT (which would expand entities) or
        // LIBXML_DTDLOAD (which would fetch external DTDs).
        if (! $dom->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS)) {
            libxml_use_internal_errors($prev);
            if ($throwOnInvalid) {
                throw new ValidationException([
                    'badge_svg' => 'Invalid SVG: could not parse XML.',
                ]);
            }
            return '';
        }

        libxml_use_internal_errors($prev);

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
    }

    /**
     * Walks the SVG and rewrites every `fill` attribute (other than `none`,
     * `transparent`, or a whiteish value) to `currentColor`. This means the
     * tier color (or the forum's primary colour) drives the visible colour
     * of the uploaded artwork — while preserving any explicit WHITE inner
     * shapes (typically the inner check on a verified-style seal), so the
     * "middle white" stays white on dark backgrounds.
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
            self::replaceFillsWithCurrentColor($child);
        }
    }

    /**
     * Recognises common ways an SVG can express "white" — preserved so the
     * inner check of a verified-style seal stays white instead of being
     * recoloured to currentColor.
     */
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

    private static function cleanNode(\DOMNode $node): void
    {
        // - script/foreignobject/iframe/object/embed/base/link/style: classic
        //   active-content vectors.
        // - a: a verified badge has no business carrying a clickable link;
        //   stripping it kills phishing-via-uploaded-badge.
        // - animate/set/animateTransform/animateMotion: SMIL animations can
        //   rewrite attributes (e.g. xlink:href) AFTER our static sanitiser
        //   runs, smuggling javascript: URIs past the attribute scrub.
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
            }
        }

        foreach ($remove as $attrName) {
            $node->removeAttribute($attrName);
        }
    }
}
