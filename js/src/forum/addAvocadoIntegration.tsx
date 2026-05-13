import { override } from "flarum/common/extend";

import type User from "flarum/common/models/User";

import { findVnodeByClass, makeVerifiedVnode } from "./utils/vnodeTree";

/**
 * Hook into avocado's components to inject the verified badge.
 *
 * Avocado v2 publishes its internal classes through Flarum's module
 * registry: each chunk (eagerly bundled or lazily loaded) calls
 * `flarum.reg.add('ramon-avocado', '<path>', module)` once available, and
 * `flarum.reg.onLoad` fires the callback either immediately (if the module
 * is already registered) or when the chunk later loads. This lets us patch
 * avocado's classes without scanning vnode trees at render time, and it
 * works regardless of whether the route component is eagerly bundled or
 * exposed as an async chunk loader on `app.routes[name].component`.
 *
 * Affected surfaces:
 *  - Profile hero        → AvocadoUserBase.view  (parent of AvocadoUser*Page)
 *  - Thread cards        → AvocadoHome / AvocadoSearch lists
 *  - Post cards          → user profile post tabs, search results
 *  - UserPage subclasses → Settings/Security pages that still derive from
 *                          core UserPage (avocado leaves these alone)
 */
export default function addAvocadoIntegration(): void {
  // ── Profile hero on core-derived UserPage subclasses (Settings, Security) ──
  override(
    "flarum/forum/components/UserPage",
    "view",
    function (this: any, original: Function, ...args: unknown[]) {
      const tree = original ? original.apply(this, args) : null;
      try {
        injectIntoHeroName(tree, this.user);
      } catch {
        /* defensive */
      }
      return tree;
    },
  );

  // ── Profile hero on AvocadoUser*Page ──────────────────────────────────────
  // Avocado's user pages extend a private `AvocadoUserBase` whose `view()`
  // does NOT call super, so wrapping core UserPage doesn't reach them. We
  // grab the parent prototype from any exported subclass and patch its view.
  flarum.reg.onLoad(
    "ramon-avocado",
    "forum/components/UserProfilePage",
    (mod: any) => {
      if (!mod) return;
      const sample =
        mod.AvocadoUserPostsPage ||
        mod.AvocadoUserDiscussionsPage ||
        mod.AvocadoUserLikesPage ||
        mod.AvocadoUserMentionsPage;
      if (!sample || !sample.prototype) return;

      const baseProto = Object.getPrototypeOf(sample.prototype);
      if (!baseProto || typeof baseProto.view !== "function") return;

      patchHeroView(baseProto);
    },
  );

  // ── Thread cards on the home / tag / search pages ─────────────────────────
  flarum.reg.onLoad(
    "ramon-avocado",
    "forum/components/shared/ThreadCard",
    (Component: any) => {
      if (!Component || !Component.prototype) return;
      wrapCardView(Component, (inst: any) => {
        const d = inst?.attrs?.discussion;
        return d && d.user && d.user();
      });
    },
  );

  // ── PostCard (user profile / search results) ──────────────────────────────
  flarum.reg.onLoad(
    "ramon-avocado",
    "forum/components/shared/PostCard",
    (Component: any) => {
      if (!Component || !Component.prototype) return;
      wrapCardView(Component, (inst: any) => {
        const p = inst?.attrs?.post;
        const d = inst?.attrs?.discussion;
        return (p && p.user && p.user()) || (d && d.user && d.user());
      });
    },
  );
}

// ─── Idempotency guards ─────────────────────────────────────────────────────
// `flarum.reg.add` may fire onLoad more than once when the same module is
// registered from multiple chunks (e.g. PostCard ships in both UserProfilePage
// and AvocadoSearchPage chunks). Without these guards we'd double-wrap and
// render two badges per card.

const patchedHeroProtos = new WeakSet<object>();
const patchedCards = new WeakSet<Function>();

// ─── Hero view patching ─────────────────────────────────────────────────────

function patchHeroView(proto: any): void {
  if (patchedHeroProtos.has(proto)) return;
  patchedHeroProtos.add(proto);

  const originalView = proto.view;
  proto.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      injectIntoHeroName(tree, (this as any).user);
    } catch {
      /* defensive */
    }
    return tree;
  };
}

function injectIntoHeroName(
  tree: unknown,
  user: User | undefined | null,
): void {
  if (!tree) return;
  if (!user || !user.isVerified || !user.isVerified()) return;

  const heroName = findVnodeByClass(tree, "AvocadoUserPage-hero-name");
  if (!heroName) return;

  const badge = makeVerifiedVnode(user, "VerifiedBadge--card");
  if (!badge) return;

  const existing = heroName.children;
  const base =
    existing == null
      ? []
      : Array.isArray(existing)
        ? existing.slice()
        : [existing];
  heroName.children = [...base, badge];
}

// ─── Card view patching ─────────────────────────────────────────────────────

function wrapCardView(
  Component: any,
  getUser: (inst: any) => User | null | undefined,
): void {
  if (patchedCards.has(Component)) return;
  patchedCards.add(Component);

  const originalView = Component.prototype.view;
  Component.prototype.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      const user = getUser(this);
      if (user && user.isVerified && user.isVerified()) {
        const meta =
          findVnodeByClass(tree, "AvocadoHome-threadMeta") ||
          findVnodeByClass(tree, "AvocadoSearch-threadMeta");

        if (meta && Array.isArray(meta.children)) {
          const authorIdx = meta.children.findIndex(
            (c: any) =>
              c &&
              c.attrs &&
              typeof c.attrs.className === "string" &&
              (c.attrs.className.indexOf("AvocadoHome-threadAuthor") !== -1 ||
                c.attrs.className.indexOf("AvocadoSearch-threadAuthor") !== -1),
          );
          if (authorIdx !== -1) {
            const badge = makeVerifiedVnode(user, "");
            if (badge) {
              meta.children = [
                ...meta.children.slice(0, authorIdx + 1),
                badge,
                ...meta.children.slice(authorIdx + 1),
              ];
            }
          }
        }
      }
    } catch {
      // Defensive — never crash card rendering.
    }
    return tree;
  };
}
