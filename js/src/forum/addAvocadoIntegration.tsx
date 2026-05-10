import { override } from 'flarum/common/extend';

import type User from 'flarum/common/models/User';

import { findVnodeByClass, makeVerifiedVnode } from './utils/vnodeTree';

/**
 * Hook into avocado's components to inject the verified badge.
 *
 * Avocado renders user pages and home cards through its own components, so
 * we can't rely on the standard Flarum extension points alone — we patch the
 * vnode trees they return after the original `view` runs.
 */
export default function addAvocadoIntegration(): void {
  // ── Profile hero: AvocadoUserPage-hero-name ───────────────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/UserProfilePage', (mod: any) => {
    if (!mod) return;
    const sample = mod.AvocadoUserPostsPage || mod.AvocadoUserDiscussionsPage
                || mod.AvocadoUserLikesPage  || mod.AvocadoUserMentionsPage;
    if (!sample || !sample.prototype) return;

    const baseProto = Object.getPrototypeOf(sample.prototype);
    if (!baseProto || typeof baseProto.view !== 'function') return;

    patchHeroView(baseProto);
  });

  override('flarum/forum/components/UserPage', 'view', function (this: any, original: Function, ...args: unknown[]) {
    const tree = original ? original.apply(this, args) : null;
    try {
      injectIntoHeroName(tree, this.user);
    } catch (e) { /* defensive */ }
    return tree;
  });

  // ── Thread cards on the home / tag / search pages ─────────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/ThreadCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode: any) => {
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return discussion && discussion.user && discussion.user();
    });
  });

  // ── PostCard (used in user profile / search results) ──────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/PostCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode: any) => {
      const post = vnode.attrs && vnode.attrs.post;
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return (post && post.user && post.user())
        || (discussion && discussion.user && discussion.user());
    });
  });
}

/**
 * Wrap a class prototype's `view` so that, after the original view returns
 * a vnode tree, we walk it looking for `.AvocadoUserPage-hero-name` and
 * append the verified badge into that h1.
 */
function patchHeroView(proto: any): void {
  const originalView = proto.view;
  proto.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      injectIntoHeroName(tree, this.user);
    } catch (e) {
      // Defensive — never crash the page.
    }
    return tree;
  };
}

/**
 * Walk the vnode tree, find the avocado hero h1, and append the badge into
 * its children.
 */
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

/**
 * Wrap a thread/post card component's view to inject the verified badge
 * after its author link.
 */
function wrapCardView(Component: any, getUser: (vnode: any) => User | null | undefined): void {
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
    } catch (e) {
      // Defensive — never crash card rendering.
    }
    return tree;
  };
}
