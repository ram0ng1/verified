import type Mithril from "mithril";

/**
 * Ponto único de m.trust do bundle. Só recebe HTML que JÁ passou por
 * sanitização: SVG de badge (sanitizado no upload e no sanitizeSvg do
 * getBadgeSvg) e descrição de tier (sanitiseDescription espelhado
 * servidor+cliente). Centralizar aqui deixa a auditoria com um único
 * sink para revisar.
 */
export default function trustedHtml(html: string): Mithril.Children {
  return m.trust(html); // nosemgrep: flarum-v2-m-trust, flarum-v2-m-trust-translator
}
