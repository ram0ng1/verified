/**
 * Multi-tier badge config — frontend-side accessors.
 *
 * Reads the parsed tier list from the forum payload (`ramonVerifiedTiers`,
 * serialised by `TierConfig::parseForFrontend` on the server) on the forum
 * side, or directly from `app.data.settings['ramon-verified.tiers']` on the
 * admin side.
 *
 * Resolution rules mirror the backend in `TierConfig` so a user's badge looks
 * the same in every frontend code path.
 */

import type User from "flarum/common/models/User";

export interface VerifiedTier {
  id: string;
  label: string;
  color: string;
  description: string;
  learnMoreUrl: string;
  autoGroups: number[];
  badgeEnabled: boolean;
  badgeSvg: string;
}

export const DEFAULT_TIER_ID = "blue";

export const BADGE_SVG_MAX = 8 * 1024;

/**
 * Read the configured tiers in a context-agnostic way:
 * - Forum app: `app.forum.attribute('ramonVerifiedTiers')` (already parsed).
 * - Admin app: `app.data.settings['ramon-verified.tiers']` (raw JSON string).
 */
export function getConfiguredTiers(): VerifiedTier[] {
  try {
    if (typeof app !== "undefined") {
      // Forum side: parsed list comes through as an array.
      if (app.forum && typeof app.forum.attribute === "function") {
        const v = app.forum.attribute("ramonVerifiedTiers");
        if (Array.isArray(v)) {
          return v.map(normalise).filter(Boolean) as VerifiedTier[];
        }
      }

      // Admin side: settings hold the raw JSON string.
      const data = (
        app as unknown as { data?: { settings?: Record<string, unknown> } }
      ).data;
      const raw =
        data && data.settings && data.settings["ramon-verified.tiers"];
      if (typeof raw === "string" && raw.trim()) {
        try {
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) {
            return parsed.map(normalise).filter(Boolean) as VerifiedTier[];
          }
        } catch (e) {
          warnDev("tier config JSON parse failed", e);
        }
      }
    }
  } catch (e) {
    warnDev("tier config read failed", e);
  }

  return [];
}

/**
 * `console.warn` apenas em desenvolvimento — silencioso em produção (§40.2).
 * Tier config corrompida é raro e útil pro admin ver durante debug; em
 * produção, falhar para `[]` mantém o forum funcional sem encher console.
 */
function warnDev(msg: string, err: unknown): void {
  try {
    if (
      typeof process !== "undefined" &&
      process.env &&
      process.env.NODE_ENV === "production"
    ) {
      return;
    }
  } catch (_e) {
    /* `process` undefined em browser puro — segue pro console.warn */
  }
  if (typeof console !== "undefined" && console.warn) {
    console.warn("[verified] " + msg, err);
  }
}

/**
 * Allow only `<strong>` and `<em>` in the tier description so admins can
 * highlight key words. Everything else is escaped so the description is safe
 * to render as trusted markup. Mirrors `TierConfig::sanitiseDescription`
 * server-side — we re-run it client-side because the admin SettingsPanel
 * persists the raw textarea value before any backend trip.
 *
 * Exported so the admin tier-preview can sanitise an in-progress description
 * without round-tripping through the backend.
 */
export function sanitiseDescription(raw: string): string {
  const text = (raw || "").trim();
  if (!text) return "";

  const escaped = text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");

  return escaped.replace(/&lt;(\/?)(strong|em)&gt;/gi, "<$1$2>");
}

function normalise(row: unknown): VerifiedTier | null {
  if (!row || typeof row !== "object") return null;
  const r = row as Record<string, unknown>;

  const id = String(r.id || "")
    .trim()
    .toLowerCase();
  if (!/^[a-z0-9_-]{1,32}$/.test(id)) return null;

  const label = String(r.label || "").trim();
  if (!label) return null;

  const color =
    typeof r.color === "string" && /^#[0-9a-f]{3,8}$/i.test(r.color.trim())
      ? r.color.trim()
      : "";

  const description =
    typeof r.description === "string"
      ? sanitiseDescription(r.description).slice(0, 320)
      : "";

  let learnMoreUrl = "";
  if (typeof r.learnMoreUrl === "string") {
    const u = r.learnMoreUrl.trim();
    if (/^https?:\/\//i.test(u)) learnMoreUrl = u.slice(0, 500);
  }

  const autoGroups: number[] = [];
  if (Array.isArray(r.autoGroups)) {
    for (const g of r.autoGroups) {
      const n = parseInt(String(g), 10);
      if (Number.isFinite(n) && n > 0) autoGroups.push(n);
    }
  }

  let badgeEnabled = Boolean(r.badgeEnabled);
  let badgeSvg = "";
  if (badgeEnabled && typeof r.badgeSvg === "string") {
    const candidate = r.badgeSvg;
    if (candidate && candidate.length <= BADGE_SVG_MAX) {
      badgeSvg = candidate;
    }
  }
  if (!badgeSvg) badgeEnabled = false;

  return {
    id,
    label,
    color,
    description,
    learnMoreUrl,
    autoGroups,
    badgeEnabled,
    badgeSvg,
  };
}

/**
 * Resolve which tier applies to a given user.
 *
 * Order:
 *   1. The `verifiedTier` attribute the backend already computed (manual
 *      assignment beats group auto-grant — same as the server logic).
 *   2. Default tier (`blue`) when the user is verified but no tier matches.
 *   3. null when the user isn't verified.
 */
export function resolveTierForUser(
  user: User | null | undefined,
): VerifiedTier | null {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  const tiers = getConfiguredTiers();
  if (tiers.length === 0) return null;

  if (typeof user.verifiedTier === "function") {
    const id = user.verifiedTier();
    if (id) {
      const found = tiers.find((t) => t.id === String(id).toLowerCase());
      if (found) return found;
    }
  }

  return tiers.find((t) => t.id === DEFAULT_TIER_ID) || tiers[0];
}

/**
 * Effective badge color for a user. Returns the tier color when set;
 * null lets CSS pick the theme primary.
 */
export function getTierColor(tier: VerifiedTier | null): string | null {
  return (tier && tier.color) || null;
}
