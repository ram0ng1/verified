import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';

import VerifiedBadge from '../../common/components/VerifiedBadge';

export type VnodeLike = Mithril.Vnode<any, any> & {
  attrs?: Record<string, any>;
  children?: any;
};

export function findVnodeByClass(node: unknown, className: string): VnodeLike | null {
  if (!node || typeof node !== 'object') return null;

  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findVnodeByClass(child, className);
      if (found) return found;
    }
    return null;
  }

  const v = node as VnodeLike;
  const cls = v.attrs && v.attrs.className;
  if (typeof cls === 'string' && cls.split(/\s+/).indexOf(className) !== -1) {
    return v;
  }

  if (v.children) return findVnodeByClass(v.children, className);
  return null;
}

export function makeVerifiedVnode(user: User | null | undefined, className: string): Mithril.Vnode | null {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  return m(VerifiedBadge, { user, className });
}
