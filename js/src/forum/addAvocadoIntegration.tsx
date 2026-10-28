import { override } from 'flarum/common/extend';

import type User from 'flarum/common/models/User';

import { findVnodeByClass, makeVerifiedVnode } from './utils/vnodeTree';

/**
 * `flarum.reg.onLoad` dispara na hora se o módulo do Avocado já foi registrado
 * ou quando o chunk carregar depois, então funciona com chunks síncronos e async.
 */
export default function addAvocadoIntegration(): void {
  // Sem o tema, nada disto existe; evita varrer a árvore do UserPage a cada
  // redesenho procurando o hero do Avocado.
  if (!('ramon-avocado' in flarum.extensions)) return;

  // Hero nas páginas que ainda herdam do UserPage do core (Settings, Security).
  override('flarum/forum/components/UserPage', 'view', function (this: any, original: Function, ...args: unknown[]) {
    const tree = original ? original.apply(this, args) : null;
    try {
      injectIntoHeroName(tree, this.user);
    } catch {
      /* ignorado de propósito */
    }
    return tree;
  });

  // O `AvocadoUserBase` é privado e sua `view()` não chama super; pegamos o
  // protótipo pai a partir de qualquer subclasse exportada.
  flarum.reg.onLoad('ramon-avocado', 'forum/components/UserProfilePage', (mod: any) => {
    if (!mod) return;
    const sample = mod.AvocadoUserPostsPage || mod.AvocadoUserDiscussionsPage || mod.AvocadoUserLikesPage || mod.AvocadoUserMentionsPage;
    if (!sample || !sample.prototype) return;

    const baseProto = Object.getPrototypeOf(sample.prototype);
    if (!baseProto || typeof baseProto.view !== 'function') return;

    patchHeroView(baseProto);
  });

  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/ThreadCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (inst: any) => {
      const d = inst?.attrs?.discussion;
      return d && d.user && d.user();
    });
  });

  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/PostCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (inst: any) => {
      const p = inst?.attrs?.post;
      const d = inst?.attrs?.discussion;
      return (p && p.user && p.user()) || (d && d.user && d.user());
    });
  });
}

// onLoad pode disparar mais de uma vez quando o módulo vem em vários chunks
// (o PostCard está em dois); sem isto o card ganharia dois selos.

const patchedHeroProtos = new WeakSet<object>();
const patchedCards = new WeakSet<Function>();

function patchHeroView(proto: any): void {
  if (patchedHeroProtos.has(proto)) return;
  patchedHeroProtos.add(proto);

  const originalView = proto.view;
  proto.view = function (this: { user?: User }, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    // A árvore do Avocado muda de forma entre versões; um erro aqui derrubaria
    // o hero inteiro, então é engolido.
    try {
      injectIntoHeroName(tree, this.user);
    } catch {
      /* ignorado de propósito */
    }
    return tree;
  };
}

function injectIntoHeroName(tree: unknown, user: User | undefined | null): void {
  if (!tree) return;
  if (!user || !user.isVerified || !user.isVerified()) return;

  const heroName = findVnodeByClass(tree, 'AvocadoUserPage-hero-name');
  if (!heroName) return;

  const badge = makeVerifiedVnode(user, 'VerifiedBadge--card');
  if (!badge) return;

  const existing = heroName.children;
  const base = existing == null ? [] : Array.isArray(existing) ? existing.slice() : [existing];
  heroName.children = [...base, badge];
}

function wrapCardView(Component: any, getUser: (inst: any) => User | null | undefined): void {
  if (patchedCards.has(Component)) return;
  patchedCards.add(Component);

  const originalView = Component.prototype.view;
  Component.prototype.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      const user = getUser(this);
      if (user && user.isVerified && user.isVerified()) {
        const meta = findVnodeByClass(tree, 'AvocadoHome-threadMeta') || findVnodeByClass(tree, 'AvocadoSearch-threadMeta');

        if (meta && Array.isArray(meta.children)) {
          // No ThreadCard o autor vem dentro do UserHoverCard, não como filho
          // direto; por isso a busca é na subárvore de cada filho.
          const authorIdx = meta.children.findIndex(
            (c: any) => findVnodeByClass(c, 'AvocadoHome-threadAuthor') || findVnodeByClass(c, 'AvocadoSearch-threadAuthor')
          );
          if (authorIdx !== -1) {
            const badge = makeVerifiedVnode(user, '');
            if (badge) {
              meta.children = [...meta.children.slice(0, authorIdx + 1), badge, ...meta.children.slice(authorIdx + 1)];
            }
          }
        }
      }
    } catch {
      // Nunca derrubar o render do card.
    }
    return tree;
  };
}
