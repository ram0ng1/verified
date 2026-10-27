<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Support;

use Flarum\Foundation\ValidationException;

/**
 * Sanitiza SVGs enviados pelo admin: remove DOCTYPE/ENTITY (XXE), elementos
 * fora de `ALLOWED_TAGS`, handlers `on*`, URLs `javascript:`/`data:`, `href`
 * que não seja `#fragment` e `url(...)` externos em atributos e CSS inline.
 * Opcionalmente troca `fill` por `currentColor`, para o tier controlar a cor.
 */
class SvgSanitizer
{
    /**
     * Sem este scrub, algo como `filter="url(https://evil.example/leak.svg#x)"`
     * faria o navegador buscar um recurso externo a cada renderização do selo.
     */
    private const URL_REFERENCING_ATTRS = [
        'fill', 'stroke', 'filter', 'mask', 'clip-path',
        'marker', 'marker-start', 'marker-mid', 'marker-end',
        'cursor',
    ];

    /**
     * Allowlist, não blocklist, para falhar fechado com elementos que o sanitizador
     * não conhece. Ficam de fora de propósito `animate*`/`set`/`mpath` (o SMIL
     * reescreve `xlink:href` em runtime) e `image`/`feImage` (carregam recurso
     * externo mesmo após o scrub de `href`).
     */
    private const ALLOWED_TAGS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'switch',
        'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath',
        'lineargradient', 'radialgradient', 'stop',
        'pattern', 'clippath', 'mask', 'marker',
        'filter',
        'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite',
        'feconvolvematrix', 'fediffuselighting', 'fedisplacementmap',
        'fedropshadow', 'feflood', 'fefunca', 'fefuncb', 'fefuncg', 'fefuncr',
        'fegaussianblur', 'femerge', 'femergenode', 'femorphology', 'feoffset',
        'fespecularlighting', 'fetile', 'feturbulence',
        'fedistantlight', 'fepointlight', 'fespotlight',
    ];

    /**
     * Entrada não parseável devolve string vazia, ou lança com `throwOnInvalid`.
     */
    public static function sanitize(string $content, bool $throwOnInvalid = true): string
    {
        if ($content === '') return '';

        $content = self::stripUntilStable($content, [
            '/<!DOCTYPE\b[^>\[]*(?:\[[\s\S]*?\])?\s*>/i',
            '/<!ENTITY\b[\s\S]*?>/i',
        ]);
        $content = ltrim($content);

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
     * Repete até a string parar de mudar: uma passada só deixa recombinações como
     * `<<!--!-->!--X-->` → `<!--X-->`.
     *
     * @param string[] $patterns
     */
    private static function stripUntilStable(string $input, array $patterns): string
    {
        do {
            $previous = $input;
            foreach ($patterns as $pattern) {
                $input = (string) preg_replace($pattern, '', $input);
            }
        } while ($input !== $previous);

        return $input;
    }

    /**
     * Mantém `none`, `transparent` e brancos, preservando o check branco interno.
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

    /**
     * Só `url(#fragment)` é aceito; relativas e protocolo-relativas (`//host`)
     * também contam como externas.
     */
    private static function hasExternalUrlRef(string $value): bool
    {
        if (! preg_match_all('/url\s*\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', $value, $matches)) {
            return false;
        }
        foreach ($matches[2] as $target) {
            $target = trim($target);
            if ($target === '') continue;
            if ($target[0] !== '#') return true;
        }
        return false;
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

    private static function cleanNode(\DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                if (! in_array(strtolower($child->localName), self::ALLOWED_TAGS, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::cleanNode($child);
            } elseif ($child instanceof \DOMProcessingInstruction
                || $child instanceof \DOMComment
                || $child instanceof \DOMCdataSection) {
                // Comentários e CDATA voltam intactos no `saveXML()`; inseridos via
                // innerHTML, `<!-->` fecha o comentário e o resto escapa do SVG (mXSS).
                $node->removeChild($child);
            }
        }

        if (! ($node instanceof \DOMElement)) {
            return;
        }

        $remove = [];
        $rewrite = [];

        foreach ($node->attributes as $attr) {
            // `nodeName` é o nome qualificado (`xlink:href`); `name` seria só
            // o local (`href`). E a remoção vai pelo nó do atributo: um
            // `removeAttribute('href')` não acha o `xlink:href`.
            $name      = strtolower($attr->nodeName);
            $localName = strtolower((string) $attr->localName);
            $val       = ltrim($attr->value);

            if (str_starts_with($localName, 'on')) {
                $remove[] = $attr;
                continue;
            }

            if (preg_match('/^javascript\s*:/i', $val)) {
                $remove[] = $attr;
                continue;
            }

            if (in_array($localName, ['href', 'src', 'action'], true)
                && preg_match('/^data\s*:/i', $val)) {
                $remove[] = $attr;
                continue;
            }

            if ($localName === 'href' && ! str_starts_with($val, '#')) {
                $remove[] = $attr;
                continue;
            }

            if (in_array($name, self::URL_REFERENCING_ATTRS, true)
                && self::hasExternalUrlRef($val)) {
                $remove[] = $attr;
                continue;
            }

            if ($name === 'style' && self::hasExternalUrlRef($val)) {
                $rewrite[$attr->name] = self::stripExternalUrlRefsFromStyle($val);
            }
        }

        foreach ($remove as $attr) {
            $node->removeAttributeNode($attr);
        }

        foreach ($rewrite as $attrName => $newValue) {
            if ($newValue === '') {
                $node->removeAttribute($attrName);
            } else {
                $node->setAttribute($attrName, $newValue);
            }
        }
    }

    private static function stripExternalUrlRefsFromStyle(string $style): string
    {
        $declarations = explode(';', $style);
        $kept = [];
        foreach ($declarations as $decl) {
            if (trim($decl) === '') continue;
            if (self::hasExternalUrlRef($decl)) continue;
            $kept[] = $decl;
        }
        return trim(implode(';', $kept), " \t\n\r;");
    }
}
