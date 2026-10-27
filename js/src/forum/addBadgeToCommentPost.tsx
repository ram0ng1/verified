import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';

import VerifiedBadge from '../common/components/VerifiedBadge';
import { findVnodeByClass } from './utils/vnodeTree';

export default function addBadgeToCommentPost(): void {
  extend('flarum/forum/components/CommentPost', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    const post = this.attrs.post;
    const user: User | undefined = post && post.user && post.user();
    if (!user || !user.isVerified || !user.isVerified()) return;

    items.add('verified', <VerifiedBadge user={user} className="VerifiedBadge--post" />, 95);
  });

  // O preview de resposta (ReplyPlaceholder) monta o header direto com <PostUser>
  // e não passa por `headerItems`, então o selo é injetado aqui.
  extend('flarum/forum/components/ReplyPlaceholder', 'view', function (vnode: Mithril.Vnode<any, any>) {
    if (!vnode || vnode.tag !== 'article') return;
    const user: User | null = app.session.user;
    if (!user || !user.isVerified || !user.isVerified()) return;

    const header = findVnodeByClass(vnode, 'Post-header');
    if (!header || !Array.isArray(header.children)) return;

    // Mesmo wrapper de um post real (`ul > li.item-verified`) para herdar o alinhamento.
    header.children = [
      ...header.children,
      <ul>
        <li className="item-verified">
          <VerifiedBadge user={user} className="VerifiedBadge--post" />
        </li>
      </ul>,
    ];
  });
}
