import type Mithril from 'mithril';

// Único m.trust do bundle: só recebe SVG de sanitizeSvg e descrição de
// sanitiseDescription. Um ponto só facilita revisar o que vira HTML.
const mithrilTrust = m.trust;

export default function trustedHtml(html: string): Mithril.Children {
  return mithrilTrust(html);
}
