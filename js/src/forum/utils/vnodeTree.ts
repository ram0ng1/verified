import app from "flarum/forum/app";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";
import type User from "flarum/common/models/User";

import VerifiedBadge from "../../common/components/VerifiedBadge";
import getBadgeSvg, { getBadgeSize } from "../../common/utils/getBadgeSvg";

export type VnodeLike = Mithril.Vnode<any, any> & {
  attrs?: Record<string, any>;
  children?: any;
};

/**
 * Recursively find the first vnode in `node` whose attrs.className contains
 * the given class. Returns null if no match.
 */
export function findVnodeByClass(
  node: unknown,
  className: string,
): VnodeLike | null {
  if (!node || typeof node !== "object") return null;

  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findVnodeByClass(child, className);
      if (found) return found;
    }
    return null;
  }

  const v = node as VnodeLike;
  const cls = v.attrs && v.attrs.className;
  if (typeof cls === "string" && cls.split(/\s+/).indexOf(className) !== -1) {
    return v;
  }

  if (v.children) return findVnodeByClass(v.children, className);
  return null;
}

/**
 * Build a verified-badge vnode for use inside an avocado vnode tree.
 *
 * Returns the VerifiedBadge component when available, or falls back to a
 * plain <span> rendered via inline SVG if the component class isn't
 * accessible from this code path.
 */
export function makeVerifiedVnode(
  user: User | null | undefined,
  className: string,
): Mithril.Vnode | null {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  const Cls: any =
    (typeof VerifiedBadge === "function" ? VerifiedBadge : null) ||
    (typeof flarum !== "undefined" && flarum.reg
      ? flarum.reg.get("ramon-verified", "common/components/VerifiedBadge")
      : null);

  if (typeof Cls === "function") {
    try {
      return m(Cls, { user, className });
    } catch (e) {
      // fall through to inline span
    }
  }

  // SECURITY: `svg` is rendered via `m.trust(...)` below. The ONLY safe
  // source is `getBadgeSvg()`, which applies a 3-layer sanitiser pipeline
  // (PHP DOMDocument server-side, regex JS check, DOMParser walk). If you
  // ever change the source of `svg` to come from anywhere else (API body,
  // direct setting read, etc.) you MUST sanitise first — see
  // CLAUDE.md §9 / audit N10.
  let svg = getBadgeSvg();
  let size = "1.2em";
  let tooltip = "Verified";

  try {
    const z = getBadgeSize();
    if (typeof z === "string" && z) size = z;
    if (typeof app !== "undefined" && app.translator) {
      const t = extractText(app.translator.trans("ramon-verified.lib.tooltip"));
      if (t) tooltip = t;
    }
  } catch (e) {
    // defaults
  }

  return m(
    "span",
    {
      className: ("VerifiedBadge " + (className || "")).trim(),
      style: { "--verified-size": size },
      role: "img",
      title: tooltip,
      "aria-label": tooltip,
    },
    m.trust(svg),
  );
}
