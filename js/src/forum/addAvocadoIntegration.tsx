import { override } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import IndexPage from 'flarum/forum/components/IndexPage';

import type User from 'flarum/common/models/User';

import { findVnodeByClass, makeVerifiedVnode } from './utils/vnodeTree';

/**
 * Hook into avocado's components to inject the verified badge.
 *
 * Avocado does NOT register its internal components (ThreadCard, PostCard,
 * HomePage, AvocadoUserBase, etc.) via `flarum.reg.add()` — they are imported
 * synchronously inside the avocado bundle, so `flarum.reg.onLoad` callbacks
 * for those module names never fire. Instead we discover those classes lazily
 * by walking the vnode trees returned by avocado's route components and the
 * core IndexPage (where avocado mounts HomePage via `contentItems`). When we
 * spot a class with the ThreadCard/PostCard signature in a tree, we monkey-
 * patch its `view()` once and let it render the badge from then on.
 */
export default function addAvocadoIntegration(): void {
  // ── Profile hero on core-derived UserPage subclasses (Settings, Security) ──
  // Avocado replaces UserPage.prototype.view with its own AvocadoUserPage
  // layout (avocado index.tsx ~line 582). Wrapping it here injects the badge
  // into the hero h1 for those pages.
  override('flarum/forum/components/UserPage', 'view', function (this: any, original: Function, ...args: unknown[]) {
    const tree = original ? original.apply(this, args) : null;
    try { injectIntoHeroName(tree, this.user); } catch { /* defensive */ }
    return tree;
  });

  // ── Profile hero on AvocadoUser*Page route components ─────────────────────
  // Avocado's user pages extend a private `AvocadoUserBase` whose `view()`
  // does NOT call super, so wrapping core UserPage doesn't reach them. We get
  // the base class from the route component's prototype chain.
  patchAvocadoUserBaseHero();

  // ── ThreadCard / PostCard discovery ───────────────────────────────────────
  patchPageEntries();
}

// ─── Discovery state ─────────────────────────────────────────────────────────

const patchedPages = new WeakSet<Function>();
const patchedCards = new WeakSet<Function>();
let foundThreadCard = false;
let foundPostCard = false;

// ─── Page-tree discovery ─────────────────────────────────────────────────────

function patchPageEntries(): void {
  // IndexPage hosts <HomePage /> inside its contentItems (added by avocado).
  if (IndexPage && (IndexPage as any).prototype) {
    patchPageView(IndexPage as any);
  }

  // Every distinct route component is an entry point that may contain cards.
  const seen = new Set<Function>();
  const routes = (app as any).routes || {};
  for (const name of Object.keys(routes)) {
    const C = routes[name]?.component;
    if (typeof C === 'function' && !seen.has(C)) {
      seen.add(C);
      patchPageView(C);
    }
  }
}

function patchPageView(C: any): void {
  if (!C || !C.prototype || patchedPages.has(C)) return;
  if (typeof C.prototype.view !== 'function') return;
  patchedPages.add(C);

  const originalView = C.prototype.view;
  C.prototype.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    if (!foundThreadCard || !foundPostCard) {
      try { discoverInTree(tree); } catch { /* defensive */ }
    }
    return tree;
  };
}

/**
 * Walk a vnode tree looking for ThreadCard/PostCard classes (by attrs
 * signature) and nested page-class vnodes. When found, monkey-patch them so
 * that subsequent renders go through our wrappers. Stops walking once both
 * card classes are known.
 */
function discoverInTree(node: any): void {
  if (foundThreadCard && foundPostCard) return;
  if (!node) return;

  if (Array.isArray(node)) {
    for (const child of node) {
      if (foundThreadCard && foundPostCard) return;
      discoverInTree(child);
    }
    return;
  }
  if (typeof node !== 'object') return;

  const tag = node.tag;
  const attrs = node.attrs;

  if (typeof tag === 'function' && tag.prototype && typeof tag.prototype.view === 'function') {
    if (!foundThreadCard && attrs && attrs.discussion && !attrs.post) {
      foundThreadCard = true;
      patchCardView(tag, (inst: any) => {
        const d = inst?.attrs?.discussion;
        return d && d.user && d.user();
      });
    } else if (!foundPostCard && attrs && attrs.post) {
      foundPostCard = true;
      patchCardView(tag, (inst: any) => {
        const p = inst?.attrs?.post;
        const d = inst?.attrs?.discussion;
        return (p && p.user && p.user()) || (d && d.user && d.user());
      });
    } else if (!patchedPages.has(tag)) {
      // Unknown nested component — patch so its tree gets walked when it
      // renders (within this same render cycle, Mithril will call the wrapped
      // view next as it descends into the component).
      patchPageView(tag);
    }
  }

  discoverInTree(node.children);
}

// ─── Card view patching ──────────────────────────────────────────────────────

function patchCardView(Component: any, getUser: (vnode: any) => User | null | undefined): void {
  if (!Component || !Component.prototype || patchedCards.has(Component)) return;
  patchedCards.add(Component);

  const originalView = Component.prototype.view;
  Component.prototype.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      const user = getUser(this);
      if (user && user.isVerified && user.isVerified()) {
        const meta =
          findVnodeByClass(tree, 'AvocadoHome-threadMeta') ||
          findVnodeByClass(tree, 'AvocadoSearch-threadMeta');

        if (meta && Array.isArray(meta.children)) {
          const authorIdx = meta.children.findIndex(
            (c: any) => c && c.attrs && typeof c.attrs.className === 'string' && (
              c.attrs.className.indexOf('AvocadoHome-threadAuthor') !== -1 ||
              c.attrs.className.indexOf('AvocadoSearch-threadAuthor') !== -1
            )
          );
          if (authorIdx !== -1) {
            const badge = makeVerifiedVnode(user, '');
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

// ─── Avocado user profile hero ───────────────────────────────────────────────

function patchAvocadoUserBaseHero(): void {
  const routes = (app as any).routes || {};
  const sample =
    routes['user.posts']?.component ||
    routes['user.discussions']?.component ||
    routes['user.likes']?.component ||
    routes['user.mentions']?.component ||
    routes['user']?.component;

  if (!sample || !sample.prototype) return;

  const baseProto = Object.getPrototypeOf(sample.prototype);
  if (!baseProto || typeof baseProto.view !== 'function') return;
  if (patchedPages.has(baseProto.constructor)) return;
  patchedPages.add(baseProto.constructor);

  const originalView = baseProto.view;
  baseProto.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try { injectIntoHeroName(tree, (this as any).user); } catch { /* defensive */ }
    return tree;
  };
}

// ─── Hero name injection (unchanged) ─────────────────────────────────────────

function injectIntoHeroName(tree: unknown, user: User | undefined | null): void {
  if (!tree) return;
  if (!user || !user.isVerified || !user.isVerified()) return;

  const heroName = findVnodeByClass(tree, 'AvocadoUserPage-hero-name');
  if (!heroName) return;

  const badge = makeVerifiedVnode(user, 'VerifiedBadge--card');
  if (!badge) return;

  const existing = heroName.children;
  const base = existing == null
    ? []
    : (Array.isArray(existing) ? existing.slice() : [existing]);
  heroName.children = [...base, badge];
}
