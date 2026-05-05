/**
 * Verified-badge SVG resolution.
 *
 * Resolution order:
 *  1. Custom SVG content stored on the `ramonVerifiedBadgeSvgContent`
 *     setting (sanitised at upload time on the server). Inlined into the
 *     forum payload, so it's available SYNCHRONOUSLY on every page load
 *     with no fetch race.
 *  2. Default Twitter-style verified mark.
 *
 * The SVG fill is rewritten to `currentColor` server-side, so the badge
 * inherits the CSS `color` applied by the parent `.VerifiedBadge` element.
 */

// Twitter/X-style verified seal — split into two paths so the checkmark
// is explicitly WHITE rather than a cutout that lets the parent background
// bleed through. This keeps the checkmark visible against dark heroes
// (e.g. avocado's coloured user-page hero) and any other tinted surface.
const SEAL_PATH =
  'M20.396 11c-.018-.646-.215-1.275-.57-1.816-.354-.54-.852-.972-1.438-1.246.223-.607.27-1.264.14-1.897-.131-.634-.437-1.218-.882-1.687-.47-.445-1.053-.75-1.687-.882-.633-.13-1.29-.083-1.897.14-.273-.587-.704-1.086-1.245-1.44S11.647 1.62 11 1.604c-.646.017-1.273.213-1.813.568s-.969.854-1.24 1.44c-.608-.223-1.267-.272-1.902-.14-.635.13-1.22.436-1.69.882-.445.47-.749 1.055-.878 1.688-.13.633-.08 1.29.144 1.896-.587.274-1.087.705-1.443 1.245-.356.54-.555 1.17-.574 1.817.02.647.218 1.276.574 1.817.356.54.856.972 1.443 1.245-.224.606-.274 1.263-.144 1.896.13.634.433 1.218.877 1.688.47.443 1.054.747 1.687.878.633.132 1.29.084 1.897-.136.274.586.705 1.084 1.246 1.439.54.354 1.17.551 1.816.569.647-.016 1.276-.213 1.817-.567s.972-.854 1.245-1.44c.604.239 1.266.296 1.903.164.636-.132 1.22-.447 1.68-.907.46-.46.776-1.044.908-1.681s.075-1.299-.165-1.903c.586-.274 1.084-.705 1.439-1.246.354-.54.551-1.17.569-1.816z';
const CHECK_PATH = 'M9.662 14.85l-3.429-3.428 1.293-1.302 2.072 2.072 4.4-4.794 1.347 1.246z';

export const DEFAULT_VERIFIED_SVG =
  `<svg viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">` +
    `<path d="${SEAL_PATH}" fill="currentColor"></path>` +
    `<path d="${CHECK_PATH}" fill="#fff"></path>` +
  `</svg>`;

let cachedCustomSvg = null;
let cachedFromAttribute = null;

function normalisePath(path) {
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

export function resolveAssetUrl(assetPath) {
  if (!assetPath) return null;
  if (/^https?:\/\//i.test(assetPath)) return assetPath;
  if (/^[a-z][a-z0-9+.-]*:/i.test(assetPath)) return null;
  const base = app.forum.attribute('assetsBaseUrl') || (app.forum.attribute('baseUrl') || '') + '/assets';
  return base.replace(/\/+$/, '') + '/' + normalisePath(assetPath);
}

/**
 * Defensive sanitiser. The backend already strips scripts and event
 * handlers on upload, but we re-validate before injecting via `m.trust`
 * so a tampered settings row can't smuggle XSS through the frontend.
 */
export function sanitizeSvg(raw) {
  if (typeof raw !== 'string') return null;
  const trimmed = raw.trim();
  if (!trimmed) return null;
  if (!/^<\?xml[^?]*\?>\s*<svg[\s>]/i.test(trimmed) && !/^<svg[\s>]/i.test(trimmed)) return null;
  if (/<\s*script\b/i.test(trimmed)) return null;
  if (/\son\w+\s*=/i.test(trimmed)) return null;
  if (/javascript:/i.test(trimmed)) return null;
  if (/<!ENTITY/i.test(trimmed) || /<!DOCTYPE/i.test(trimmed)) return null;

  if (typeof DOMParser === 'undefined') return trimmed;

  try {
    const doc = new DOMParser().parseFromString(trimmed, 'image/svg+xml');
    const root = doc.documentElement;
    if (!root || root.nodeName.toLowerCase() !== 'svg') return null;
    if (root.getElementsByTagName('parsererror').length > 0) return null;

    const ALLOWED = new Set([
      'svg', 'path', 'g', 'circle', 'rect', 'polygon', 'polyline', 'line',
      'ellipse', 'defs', 'lineargradient', 'radialgradient', 'stop', 'use',
      'title', 'desc', 'symbol',
    ]);

    const walker = doc.createTreeWalker(root, 1 /* SHOW_ELEMENT */);
    const toRemove = [];
    let node = walker.currentNode;
    while (node) {
      const name = node.nodeName.toLowerCase();
      if (!ALLOWED.has(name)) {
        toRemove.push(node);
      } else {
        for (const attr of Array.from(node.attributes)) {
          const an = attr.name.toLowerCase();
          if (an.startsWith('on')) node.removeAttribute(attr.name);
          else if ((an === 'href' || an === 'xlink:href') && /javascript:/i.test(attr.value)) {
            node.removeAttribute(attr.name);
          }
        }
      }
      node = walker.nextNode();
    }
    for (const n of toRemove) n.parentNode && n.parentNode.removeChild(n);

    return new XMLSerializer().serializeToString(root);
  } catch (e) {
    return null;
  }
}

/**
 * Read a setting in a context-agnostic way:
 * - Forum context: settings serialised via `Extend\Settings::serializeToForum`
 *   live on `app.forum.attribute(<camelCaseAlias>)`.
 * - Admin context: every setting is exposed as `app.data.settings[<dottedKey>]`.
 *
 * The admin's live preview reads through these helpers, so they need to
 * work the same in both contexts.
 */
function readSetting(forumAttr, adminKey) {
  try {
    if (typeof app !== 'undefined') {
      if (app.forum && typeof app.forum.attribute === 'function') {
        const v = app.forum.attribute(forumAttr);
        if (v !== undefined && v !== null) return v;
      }
      if (app.data && app.data.settings && adminKey in app.data.settings) {
        return app.data.settings[adminKey];
      }
    }
  } catch (e) {
    // ignore
  }
  return undefined;
}

function isTruthy(v) {
  return v === true || v === 'true' || v === 1 || v === '1';
}

/**
 * Synchronously returns the badge SVG markup. Reads from the inlined
 * setting on every call (cheap), with a one-shot sanitiser cache keyed
 * on the raw attribute value so we don't re-parse on every render.
 */
export default function getBadgeSvg() {
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
  } catch (e) {
    // ignore
  }

  return DEFAULT_VERIFIED_SVG;
}

export function getBadgeSize() {
  try {
    const raw = readSetting('ramonVerifiedBadgeSize', 'ramon-verified.badge_size');
    const num = parseFloat(raw);
    if (Number.isFinite(num) && num > 0) {
      const clamped = Math.max(0.6, Math.min(num, 3));
      return clamped.toFixed(2) + 'em';
    }
  } catch (e) {
    // ignore
  }
  return '1.2em';
}

export function getBadgeColor() {
  try {
    const enabled = readSetting('ramonVerifiedCustomColorEnabled', 'ramon-verified.custom_color_enabled');
    if (!isTruthy(enabled)) return null;

    const c = readSetting('ramonVerifiedBadgeColor', 'ramon-verified.badge_color');
    if (typeof c === 'string') {
      const v = c.trim();
      if (/^#[0-9a-f]{3,8}$/i.test(v)) return v;
      if (/^rgba?\([^)]+\)$/i.test(v)) return v;
      if (/^hsla?\([^)]+\)$/i.test(v)) return v;
    }
  } catch (e) {
    // ignore
  }
  return null;
}
