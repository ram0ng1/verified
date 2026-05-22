<?php

namespace Ramon\Verified\Support;

use Flarum\Foundation\ValidationException;

/**
 * Sanitiza SVGs admin-uploaded. Remove DOCTYPE/ENTITY (XXE), todo elemento
 * fora da allowlist `ALLOWED_TAGS` (script, foreignObject, iframe, animate*,
 * etc.), handlers `on*`, URLs `javascript:` / `data:`, qualquer
 * `href`/`xlink:href` que não seja referência interna
 * `#fragment` (defeats `<use href="evil.svg#x">`, relativo ou absoluto), e
 * referências `url(...)` para recursos externos em atributos e em CSS
 * inline. Opcionalmente reescreve `fill` para `currentColor`, deixando o
 * frontend pilotar a cor pelo tier.
 *
 * Usado em três call sites — UploadBadgeSvgController (upload),
 * SanitizeTiersOnSave (persistência de settings de tier) e TierConfig
 * (parse de JSON) — então mora fora do controller para evitar acoplar
 * cada caller à camada HTTP.
 */
class SvgSanitizer
{
    /**
     * Atributos SVG/CSS que aceitam `url(...)` como referência a paint server,
     * máscara, filtro, marker ou cursor. Sem scrub aqui, um valor como
     * `filter="url(https://evil.example/leak.svg#x)"` faz o navegador buscar
     * recursos externos toda vez que o selo é renderizado.
     */
    private const URL_REFERENCING_ATTRS = [
        'fill', 'stroke', 'filter', 'mask', 'clip-path',
        'marker', 'marker-start', 'marker-mid', 'marker-end',
        'cursor',
    ];

    /**
     * Allowlist de elementos SVG aceitos — qualquer elemento fora desta
     * lista é removido. Allowlist em vez de blocklist porque falha fechado:
     * um elemento ativo novo (ou esquecido — `<animateColor>`, `<mpath>`,
     * foreign-content de outro namespace cujo `localName` colide) nunca
     * passa por não constar aqui, sem depender de o sanitizador conhecer
     * a ameaça de antemão.
     *
     * Cobre estrutura, formas, texto, gradientes/paint servers e primitivas
     * de filtro. Fora da lista de propósito: `script`, `style`, `a`,
     * `foreignObject`, `iframe`, `object`, `embed`, `base`, `link`, todos os
     * `animate*`/`set`/`mpath` (SMIL reescreve `xlink:href` em runtime,
     * contornando o scrub estático) e `image`/`feImage` (carregam recurso
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
     * Sanitiza um SVG. Devolve string vazia em entrada não-parseável;
     * `throwOnInvalid=true` lança ValidationException em vez disso.
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
     * Aplica cada padrão em loop até a string parar de mudar. Strip de
     * uma passada deixa recombinações (`<<!--!-->!--X-->` → `<!--X-->`);
     * loop até fixed-point garante remoção completa.
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

    /**
     * `true` quando o atributo (ou trecho de style) tem qualquer `url(...)`
     * que não seja referência interna do tipo `url(#fragment)`. Casa `url(`,
     * `URL(`, aspas opcionais e espaço; rejeita protocolos absolutos, URLs
     * relativas e protocolo-relativas (`//host/...`).
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
            } elseif ($child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }

        if (! ($node instanceof \DOMElement)) {
            return;
        }

        $remove = [];
        $rewrite = [];

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
                && ! str_starts_with($val, '#')) {
                $remove[] = $attr->name;
                continue;
            }

            if (in_array($name, self::URL_REFERENCING_ATTRS, true)
                && self::hasExternalUrlRef($val)) {
                $remove[] = $attr->name;
                continue;
            }

            if ($name === 'style' && self::hasExternalUrlRef($val)) {
                $rewrite[$attr->name] = self::stripExternalUrlRefsFromStyle($val);
            }
        }

        foreach ($remove as $attrName) {
            $node->removeAttribute($attrName);
        }

        foreach ($rewrite as $attrName => $newValue) {
            if ($newValue === '') {
                $node->removeAttribute($attrName);
            } else {
                $node->setAttribute($attrName, $newValue);
            }
        }
    }

    /**
     * Remove declarações CSS cujo valor contém `url(...)` apontando para fora
     * (qualquer coisa que não seja `url(#fragment)`). Preserva o resto do
     * style — fill, transform, etc. seguem válidos.
     */
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
