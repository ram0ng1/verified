import { extend } from 'flarum/common/extend';

import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';

import VerifiedBadge from '../common/components/VerifiedBadge';

/**
 * Add the verified badge as its own item in the post header item list.
 */
export default function addBadgeToCommentPost(): void {
  extend('flarum/forum/components/CommentPost', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    const post = this.attrs.post;
    const user: User | undefined = post && post.user && post.user();
    if (!user || !user.isVerified || !user.isVerified()) return;

    items.add(
      'verified',
      <VerifiedBadge user={user} className="VerifiedBadge--post" />,
      95
    );
  });
}
