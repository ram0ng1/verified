/**
 * Verified-badge SVG resolution.
 *
 * Resolution order:
 *  1. Custom SVG content stored on the `ramonVerifiedBadgeSvgContent`
 *     setting (sanitised at upload time on the server). Inlined into the
 *     forum payload ONLY when small enough — see
 *     `UploadBadgeSvgController::INLINE_SVG_THRESHOLD` (8 KB). When set,
 *     it's available synchronously on every page load with no fetch race.
 *  2. Async-fetched custom SVG: the asset at `ramonVerifiedBadgeSvgPath`
 *     is loaded over HTTP once per session, sanitised in the browser,
 *     cached in module memory + sessionStorage, and triggers a redraw
 *     when ready. Used for larger custom SVGs that aren't worth inlining
 *     into every forum payload (audit H-SVG).
 *  3. Default Twitter-style verified mark.
 *
 * The SVG fill is rewritten to `currentColor` server-side, so the badge
 * inherits the CSS `color` applied by the parent `.VerifiedBadge` element.
 */

const SEAL_PATH =
  "M20.396 11c-.018-.646-.215-1.275-.57-1.816-.354-.54-.852-.972-1.438-1.246.223-.607.27-1.264.14-1.897-.131-.634-.437-1.218-.882-1.687-.47-.445-1.053-.75-1.687-.882-.633-.13-1.29-.083-1.897.14-.273-.587-.704-1.086-1.245-1.44S11.647 1.62 11 1.604c-.646.017-1.273.213-1.813.568s-.969.854-1.24 1.44c-.608-.223-1.267-.272-1.902-.14-.635.13-1.22.436-1.69.882-.445.47-.749 1.055-.878 1.688-.13.633-.08 1.29.144 1.896-.587.274-1.087.705-1.443 1.245-.356.54-.555 1.17-.574 1.817.02.647.218 1.276.574 1.817.356.54.856.972 1.443 1.245-.224.606-.274 1.263-.144 1.896.13.634.433 1.218.877 1.688.47.443 1.054.747 1.687.878.633.132 1.29.084 1.897-.136.274.586.705 1.084 1.246 1.439.54.354 1.17.551 1.816.569.647-.016 1.276-.213 1.817-.567s.972-.854 1.245-1.44c.604.239 1.266.296 1.903.164.636-.132 1.22-.447 1.68-.907.46-.46.776-1.044.908-1.681s.075-1.299-.165-1.903c.586-.274 1.084-.705 1.439-1.246.354-.54.551-1.17.569-1.816z";
const CHECK_PATH =
  "M9.662 14.85l-3.429-3.428 1.293-1.302 2.072 2.072 4.4-4.794 1.347 1.246z";

const DEFAULT_VERIFIED_SVG: string =
  `<svg viewBox="0 0 22 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">` +
  `<path d="${SEAL_PATH}" fill="currentColor"></path>` +
  `<path d="${CHECK_PATH}" fill="#fff"></path>` +
  `</svg>`;

let cachedCustomSvg: string | null = null;
let cachedFromAttribute: string | null = null;

// State for the async URL-fetch path.
let cachedFetchedSvg: string | null = null;
let cachedFetchedFromUrl: string | null = null;
let inflightFetchUrl: string | null = null;

const SESSION_STORAGE_KEY = "ramon-verified.badge-svg-cache.v1";

function normalisePath(path: string): string {
  return String(path)
    .replace(/\\/g, "/")
    .replace(/\/+/g, "/")
    .split("/")
    .filter((seg, i) => {
      if (seg === "." || seg === "") return i === 0;
      if (seg === "..") return false;
      return true;
    })
    .join("/");
}

export function resolveAssetUrl(
  assetPath: string | null | undefined,
): string | null {
  if (!assetPath) return null;
  if (/^https?:\/\//i.test(assetPath)) return assetPath;
  if (/^[a-z][a-z0-9+.-]*:/i.test(assetPath)) return null;
  const base =
    (app.forum.attribute<string>("assetsBaseUrl") as string | undefined) ||
    ((app.forum.attribute<string>("baseUrl") as string | undefined) || "") +
      "/assets";
  return base.replace(/\/+$/, "") + "/" + normalisePath(assetPath);
}

/**
 * Defensive sanitiser. The backend already strips scripts and event
 * handlers on upload, but we re-validate before injecting via `m.trust`
 * so a tampered settings row can't smuggle XSS through the frontend.
 */
export function sanitizeSvg(raw: unknown): string | null {
  if (typeof raw !== "string") return null;
  const trimmed = raw.trim();
  if (!trimmed) return null;
  if (
    !/^<\?xml[^?]*\?>\s*<svg[\s>]/i.test(trimmed) &&
    !/^<svg[\s>]/i.test(trimmed)
  )
    return null;
  if (/<\s*script\b/i.test(trimmed)) return null;
  if (/\son\w+\s*=/i.test(trimmed)) return null;
  if (/javascript:/i.test(trimmed)) return null;
  if (/<!ENTITY/i.test(trimmed) || /<!DOCTYPE/i.test(trimmed)) return null;

  if (typeof DOMParser === "undefined") return trimmed;

  try {
    const doc = new DOMParser().parseFromString(trimmed, "image/svg+xml");
    const root = doc.documentElement;
    if (!root || root.nodeName.toLowerCase() !== "svg") return null;
    if (root.getElementsByTagName("parsererror").length > 0) return null;

    const ALLOWED = new Set([
      "svg",
      "path",
      "g",
      "circle",
      "rect",
      "polygon",
      "polyline",
      "line",
      "ellipse",
      "defs",
      "lineargradient",
      "radialgradient",
      "stop",
      "use",
      "title",
      "desc",
      "symbol",
    ]);

    const walker = doc.createTreeWalker(root, 1 /* SHOW_ELEMENT */);
    const toRemove: Element[] = [];
    let node: Node | null = walker.currentNode;
    while (node) {
      const name = node.nodeName.toLowerCase();
      if (!ALLOWED.has(name)) {
        toRemove.push(node as Element);
      } else {
        const el = node as Element;
        for (const attr of Array.from(el.attributes)) {
          const an = attr.name.toLowerCase();
          if (an.startsWith("on")) {
            el.removeAttribute(attr.name);
          } else if (an === "href" || an === "xlink:href") {
            // Reject `javascript:` AND cross-origin / protocol-relative
            // URLs — the latter lets a tampered custom-badge SVG turn
            // every render into an outbound GET to a third-party origin
            // (tracker / SSRF-lite). The server-side sanitiser mirrors
            // this rejection.
            if (
              /javascript:/i.test(attr.value) ||
              /^(https?:)?\/\//i.test(attr.value)
            ) {
              el.removeAttribute(attr.name);
            }
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
 */
function readSetting(forumAttr: string, adminKey: string): unknown {
  try {
    if (typeof app !== "undefined") {
      if (app.forum && typeof app.forum.attribute === "function") {
        const v = app.forum.attribute(forumAttr);
        if (v !== undefined && v !== null) return v;
      }
      const data = (
        app as unknown as { data?: { settings?: Record<string, unknown> } }
      ).data;
      if (data && data.settings && adminKey in data.settings) {
        return data.settings[adminKey];
      }
    }
  } catch (e) {
    // ignore
  }
  return undefined;
}

/**
 * Hydrate the URL-fetch cache from sessionStorage on first call. This
 * means a forum-wide navigation reusing the same custom SVG only pays
 * the fetch on the very first page load of the session.
 */
function loadSessionCache(): void {
  if (cachedFetchedSvg !== null || cachedFetchedFromUrl !== null) return;
  try {
    if (typeof sessionStorage === "undefined") return;
    const raw = sessionStorage.getItem(SESSION_STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw) as { url?: string; svg?: string };
    if (
      parsed &&
      typeof parsed.url === "string" &&
      typeof parsed.svg === "string"
    ) {
      cachedFetchedFromUrl = parsed.url;
      cachedFetchedSvg = parsed.svg;
    }
  } catch (e) {
    // ignore
  }
}

function saveSessionCache(url: string, svg: string): void {
  try {
    if (typeof sessionStorage === "undefined") return;
    sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ url, svg }));
  } catch (e) {
    // QuotaExceededError, private mode, etc — silent fail; in-memory
    // cache still works for the rest of the session.
  }
}

/**
 * Kick off an async fetch of the custom SVG at `url`. Resolves the
 * cached, sanitised content into module state and triggers a Mithril
 * redraw so live components pick it up. Idempotent — concurrent calls
 * for the same URL coalesce.
 */
function fetchCustomSvg(url: string): void {
  if (cachedFetchedFromUrl === url && cachedFetchedSvg !== null) return;
  if (inflightFetchUrl === url) return;
  inflightFetchUrl = url;

  // Same-origin guard (CLAUDE.md §14): only fetch when the asset URL
  // lives on the forum's own origin. A tampered setting that pointed
  // at an external host would otherwise turn this into a browser-side
  // SSRF helper.
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

  fetch(parsed.href, { credentials: "same-origin" })
    .then((response) => {
      if (!response.ok) return null;
      const contentLength = response.headers.get("Content-Length");
      // 256 KB ceiling matches the server-side upload cap. A misbehaving
      // asset URL handing back a megabyte stream gets short-circuited
      // BEFORE we buffer the body.
      if (contentLength && parseInt(contentLength, 10) > 256 * 1024)
        return null;
      return response.text();
    })
    .then((raw) => {
      inflightFetchUrl = null;
      if (raw === null) return;
      // Hard cap on body size even when Content-Length is missing.
      if (raw.length > 256 * 1024) return;
      const clean = sanitizeSvg(raw);
      if (!clean) return;
      cachedFetchedSvg = clean;
      cachedFetchedFromUrl = url;
      saveSessionCache(url, clean);
      try {
        if (typeof m !== "undefined" && typeof m.redraw === "function") {
          m.redraw();
        }
      } catch (e) {
        // ignore
      }
    })
    .catch(() => {
      inflightFetchUrl = null;
    });
}

/**
 * Synchronously returns the badge SVG markup. Uses the inlined setting
 * when available, falls back to a previously-fetched-and-cached SVG
 * from the asset URL, otherwise returns the default mark while a
 * background fetch (if a custom path is configured) populates the
 * cache for the next render.
 */
export default function getBadgeSvg(): string {
  try {
    const raw = readSetting(
      "ramonVerifiedBadgeSvgContent",
      "ramon-verified.badge_svg_content",
    );
    if (typeof raw === "string" && raw.trim()) {
      if (cachedFromAttribute !== raw) {
        cachedCustomSvg = sanitizeSvg(raw);
        cachedFromAttribute = raw;
      }
      if (cachedCustomSvg) return cachedCustomSvg;
    } else if (cachedFromAttribute !== null) {
      cachedCustomSvg = null;
      cachedFromAttribute = null;
    }

    // No inline content available — try the URL-fetched cache. This
    // covers the audit H-SVG case where a large custom SVG was uploaded
    // but stripped from the forum payload; the file URL is still
    // serialised via `ramonVerifiedBadgeSvgPath`.
    const pathRaw = readSetting(
      "ramonVerifiedBadgeSvgPath",
      "ramon-verified.badge_svg_path",
    );
    if (typeof pathRaw === "string" && pathRaw.trim()) {
      const url = resolveAssetUrl(pathRaw);
      if (url) {
        loadSessionCache();
        if (cachedFetchedFromUrl === url && cachedFetchedSvg) {
          return cachedFetchedSvg;
        }
        // Trigger a background fetch on the first render that needs it.
        fetchCustomSvg(url);
      }
    }
  } catch (e) {
    // ignore
  }

  return DEFAULT_VERIFIED_SVG;
}

export function getBadgeSize(): string {
  try {
    const raw = readSetting(
      "ramonVerifiedBadgeSize",
      "ramon-verified.badge_size",
    );
    const num = parseFloat(raw as string);
    if (Number.isFinite(num) && num > 0) {
      const clamped = Math.max(0.6, Math.min(num, 3));
      return clamped.toFixed(2) + "em";
    }
  } catch (e) {
    // ignore
  }
  return "1.2em";
}
