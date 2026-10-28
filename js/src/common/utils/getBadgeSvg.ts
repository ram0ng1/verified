import app from 'flarum/common/app';

import type { VerifiedTier } from './tiers';

const SEAL_PATH =
  'M20.396 11c-.018-.646-.215-1.275-.57-1.816-.354-.54-.852-.972-1.438-1.246.223-.607.27-1.264.14-1.897-.131-.634-.437-1.218-.882-1.687-.47-.445-1.053-.75-1.687-.882-.633-.13-1.29-.083-1.897.14-.273-.587-.704-1.086-1.245-1.44S11.647 1.62 11 1.604c-.646.017-1.273.213-1.813.568s-.969.854-1.24 1.44c-.608-.223-1.267-.272-1.902-.14-.635.13-1.22.436-1.69.882-.445.47-.749 1.055-.878 1.688-.13.633-.08 1.29.144 1.896-.587.274-1.087.705-1.443 1.245-.356.54-.555 1.17-.574 1.817.02.647.218 1.276.574 1.817.356.54.856.972 1.443 1.245-.224.606-.274 1.263-.144 1.896.13.634.433 1.218.877 1.688.47.443 1.054.747 1.687.878.633.132 1.29.084 1.897-.136.274.586.705 1.084 1.246 1.439.54.354 1.17.551 1.816.569.647-.016 1.276-.213 1.817-.567s.972-.854 1.245-1.44c.604.239 1.266.296 1.903.164.636-.132 1.22-.447 1.68-.907.46-.46.776-1.044.908-1.681s.075-1.299-.165-1.903c.586-.274 1.084-.705 1.439-1.246.354-.54.551-1.17.569-1.816z';
const CHECK_PATH = 'M9.662 14.85l-3.429-3.428 1.293-1.302 2.072 2.072 4.4-4.794 1.347 1.246z';

const DEFAULT_VERIFIED_SVG: string =
  `<svg viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">` +
  `<path d="${SEAL_PATH}" fill="currentColor"></path>` +
  `<path d="${CHECK_PATH}" fill="#fff"></path>` +
  `</svg>`;

let cachedCustomSvg: string | null = null;
let cachedFromAttribute: string | null = null;

// No fórum bastam poucas entradas, mas no TiersEditor cada edição gera um SVG
// novo e o cache cresceria sem limite.
const TIER_BADGE_CACHE_LIMIT = 32;
const tierBadgeCache = new Map<string, string | null>();

function rememberTierBadge(raw: string, clean: string | null): void {
  if (tierBadgeCache.has(raw)) {
    tierBadgeCache.delete(raw);
  } else if (tierBadgeCache.size >= TIER_BADGE_CACHE_LIMIT) {
    const oldest = tierBadgeCache.keys().next().value;
    if (oldest !== undefined) tierBadgeCache.delete(oldest);
  }
  tierBadgeCache.set(raw, clean);
}

let cachedFetchedSvg: string | null = null;
let cachedFetchedFromUrl: string | null = null;
let inflightFetchUrl: string | null = null;

const SESSION_STORAGE_KEY = 'ramon-verified.badge-svg-cache.v1';

function normalisePath(path: string): string {
  return String(path)
    .replace(/\\/g, '/')
    .replace(/\/+/g, '/')
    .split('/')
    .filter((seg, i) => {
      if (seg === '.' || seg === '') return i === 0;
      if (seg === '..') return false;
      return true;
    })
    .join('/');
}

export function resolveAssetUrl(assetPath: string | null | undefined): string | null {
  if (!assetPath) return null;
  if (/^https?:\/\//i.test(assetPath)) return assetPath;
  if (/^[a-z][a-z0-9+.-]*:/i.test(assetPath)) return null;
  const base =
    (app.forum.attribute<string>('assetsBaseUrl') as string | undefined) ||
    ((app.forum.attribute<string>('baseUrl') as string | undefined) || '') + '/assets';
  return base.replace(/\/+$/, '') + '/' + normalisePath(assetPath);
}

// Uma passada só pode deixar pedaços que se recombinam:
// `<<!--!-->!--X-->` vira `<!--X-->`.
function stripUntilStable(input: string, patterns: RegExp[]): string {
  let previous: string;
  let current = input;
  do {
    previous = current;
    for (const pattern of patterns) {
      current = current.replace(pattern, '');
    }
  } while (current !== previous);
  return current;
}

// O servidor já sanitiza no upload; repetir antes do m.trust impede que uma
// setting adulterada leve XSS ao frontend. DOCTYPE e ENTITY são removidos em
// vez de rejeitados porque quase todo SVG exportado traz DOCTYPE.
export function sanitizeSvg(raw: unknown): string | null {
  if (typeof raw !== 'string') return null;
  let trimmed = raw.trim();
  if (!trimmed) return null;

  trimmed = stripUntilStable(trimmed, [/<!DOCTYPE\b[^>\[]*(?:\[[\s\S]*?\])?\s*>/gi, /<!ENTITY\b[\s\S]*?>/gi, /<!--[\s\S]*?-->/g])
    .replace(/^<\?xml\b[^?]*\?>/i, '')
    .trim();
  if (!trimmed) return null;

  if (!/^<svg[\s>]/i.test(trimmed)) return null;
  if (/<\s*script\b/i.test(trimmed)) return null;
  // Via innerHTML, `<?x >` vira um comentário que termina no primeiro `>`,
  // e o que vem depois escapa do conteúdo SVG.
  if (/<\?/.test(trimmed)) return null;
  // `/` separa atributos tanto quanto o espaço (`<img/onerror=…>`).
  if (/[\s/]on\w+\s*=/i.test(trimmed)) return null;
  if (/javascript:/i.test(trimmed)) return null;

  if (typeof DOMParser === 'undefined') return trimmed;

  try {
    const doc = new DOMParser().parseFromString(trimmed, 'image/svg+xml');
    const root = doc.documentElement;
    if (!root || root.nodeName.toLowerCase() !== 'svg') return null;
    if (root.getElementsByTagName('parsererror').length > 0) return null;

    const ALLOWED = new Set([
      'svg',
      'path',
      'g',
      'circle',
      'rect',
      'polygon',
      'polyline',
      'line',
      'ellipse',
      'defs',
      'lineargradient',
      'radialgradient',
      'stop',
      'use',
      'title',
      'desc',
      'symbol',
    ]);

    // SHOW_ALL, não SHOW_ELEMENT: senão o XMLSerializer devolve intactos
    // comentários, CDATA e processing instructions.
    const walker = doc.createTreeWalker(root, 0xffffffff);
    const toRemove: Node[] = [];
    let node: Node | null = walker.currentNode;
    while (node) {
      const name = node.nodeName.toLowerCase();
      if (node.nodeType === 3) {
        // Texto é inofensivo.
      } else if (node.nodeType !== 1 || !ALLOWED.has(name)) {
        toRemove.push(node);
      } else {
        const el = node as Element;
        for (const attr of Array.from(el.attributes)) {
          const an = attr.name.toLowerCase();
          if (an.startsWith('on')) {
            el.removeAttribute(attr.name);
          } else if (an === 'href' || an.endsWith(':href')) {
            // Só referências internas (`#id`): um badge nunca carrega nada de fora.
            if (!/^#[\w.:-]*$/.test(attr.value.trim())) {
              el.removeAttributeNode(attr);
            }
          }
        }

        rewriteFillToCurrentColor(el);
      }
      node = walker.nextNode();
    }
    for (const n of toRemove) n.parentNode && n.parentNode.removeChild(n);

    return new XMLSerializer().serializeToString(root);
  } catch (e) {
    return null;
  }
}

// Espelha UploadBadgeSvgController::replaceFillsWithCurrentColor; sem isso o
// preview do admin não herda a cor do tier antes do save.
function rewriteFillToCurrentColor(el: Element): void {
  if (el.hasAttribute('fill')) {
    const v = (el.getAttribute('fill') || '').trim().toLowerCase();
    const skip =
      v === '' ||
      v === 'none' ||
      v === 'transparent' ||
      v === 'white' ||
      v === '#fff' ||
      v === '#ffffff' ||
      /^rgba?\(\s*255\s*,\s*255\s*,\s*255(\s*,\s*[0-9.]+)?\s*\)$/.test(v);
    if (!skip) el.setAttribute('fill', 'currentColor');
  }

  if (el.hasAttribute('style')) {
    const cleaned = (el.getAttribute('style') || '')
      .replace(/\s*fill\s*:\s*[^;]+;?/gi, '')
      .replace(/[;\s]+$/, '')
      .trim();
    if (cleaned === '') el.removeAttribute('style');
    else el.setAttribute('style', cleaned);
  }
}

function readSetting(forumAttr: string, adminKey: string): unknown {
  // Admin primeiro: o payload do fórum também existe no admin, mas não
  // acompanha o que acabou de ser salvo.
  const settings = (app as unknown as { data?: { settings?: Record<string, unknown> } }).data?.settings;
  if (settings && adminKey in settings) return settings[adminKey];

  const v = app.forum?.attribute(forumAttr);
  return v ?? undefined;
}

function loadSessionCache(): void {
  if (cachedFetchedSvg !== null || cachedFetchedFromUrl !== null) return;
  try {
    if (typeof sessionStorage === 'undefined') return;
    const raw = sessionStorage.getItem(SESSION_STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw) as { url?: string; svg?: string };
    if (parsed && typeof parsed.url === 'string' && typeof parsed.svg === 'string') {
      cachedFetchedFromUrl = parsed.url;
      cachedFetchedSvg = parsed.svg;
    }
  } catch (e) {
    // ignorado
  }
}

function saveSessionCache(url: string, svg: string): void {
  try {
    if (typeof sessionStorage === 'undefined') return;
    sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ url, svg }));
  } catch (e) {
    // Cota cheia ou modo privado: o cache em memória continua valendo.
  }
}

function fetchCustomSvg(url: string): void {
  if (cachedFetchedFromUrl === url && cachedFetchedSvg !== null) return;
  if (inflightFetchUrl === url) return;
  inflightFetchUrl = url;

  let parsed: URL;
  try {
    parsed = new URL(url, location.origin);
  } catch (e) {
    inflightFetchUrl = null;
    return;
  }
  if (parsed.origin !== location.origin) {
    inflightFetchUrl = null;
    return;
  }

  fetch(parsed.href, { credentials: 'same-origin' })
    .then((response) => {
      if (!response.ok) return null;
      const contentLength = response.headers.get('Content-Length');
      // Mesmo limite do upload no servidor, checado antes de ler o corpo.
      if (contentLength && parseInt(contentLength, 10) > 256 * 1024) return null;
      return response.text();
    })
    .then((raw) => {
      inflightFetchUrl = null;
      if (raw === null) return;
      // Content-Length pode faltar.
      if (raw.length > 256 * 1024) return;
      const clean = sanitizeSvg(raw);
      if (!clean) return;
      cachedFetchedSvg = clean;
      cachedFetchedFromUrl = url;
      saveSessionCache(url, clean);
      try {
        if (typeof m !== 'undefined' && typeof m.redraw === 'function') {
          m.redraw();
        }
      } catch (e) {
        // ignorado
      }
    })
    .catch(() => {
      inflightFetchUrl = null;
    });
}

function getTierBadgeSvg(tier: VerifiedTier | null | undefined): string | null {
  if (!tier || !tier.badgeEnabled) return null;
  const raw = tier.badgeSvg;
  if (typeof raw !== 'string' || !raw.trim()) return null;

  if (tierBadgeCache.has(raw)) {
    const hit = tierBadgeCache.get(raw) ?? null;
    rememberTierBadge(raw, hit);
    return hit;
  }

  const clean = sanitizeSvg(raw);
  rememberTierBadge(raw, clean);
  return clean;
}

// Prioridade: SVG do tier, SVG global inline, SVG global por URL, padrão.
export default function getBadgeSvg(tier?: VerifiedTier | null | undefined): string {
  const tierSvg = getTierBadgeSvg(tier);
  if (tierSvg) return tierSvg;

  try {
    const raw = readSetting('ramonVerifiedBadgeSvgContent', 'ramon-verified.badge_svg_content');
    if (typeof raw === 'string' && raw.trim()) {
      if (cachedFromAttribute !== raw) {
        cachedCustomSvg = sanitizeSvg(raw);
        cachedFromAttribute = raw;
      }
      if (cachedCustomSvg) return cachedCustomSvg;
    } else if (cachedFromAttribute !== null) {
      cachedCustomSvg = null;
      cachedFromAttribute = null;
    }

    // SVG grande demais não vai inline no payload, só a URL do arquivo.
    const pathRaw = readSetting('ramonVerifiedBadgeSvgPath', 'ramon-verified.badge_svg_path');
    if (typeof pathRaw === 'string' && pathRaw.trim()) {
      const url = resolveAssetUrl(pathRaw);
      if (url) {
        loadSessionCache();
        if (cachedFetchedFromUrl === url && cachedFetchedSvg) {
          return cachedFetchedSvg;
        }
        fetchCustomSvg(url);
      }
    }
  } catch (e) {
    // ignorado
  }

  return DEFAULT_VERIFIED_SVG;
}

export function getBadgeSize(): string {
  try {
    const raw = readSetting('ramonVerifiedBadgeSize', 'ramon-verified.badge_size');
    const num = parseFloat(raw as string);
    if (Number.isFinite(num) && num > 0) {
      const clamped = Math.max(0.6, Math.min(num, 3));
      return clamped.toFixed(2) + 'em';
    }
  } catch (e) {
    // ignorado
  }
  return '1.2em';
}
