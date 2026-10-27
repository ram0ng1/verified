import { extend } from 'flarum/common/extend';

import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';

import VerifiedBadge from '../common/components/VerifiedBadge';

/**
 * Sem `plain` mesmo dentro do hover card: o popover segue só a configuração
 * `show_tooltip`, igual às demais superfícies.
 */
export default function addBadgeToUserCard(): void {
  extend('flarum/forum/components/UserCard', 'contentItems', function (this: any, items: ItemList<Mithril.Children>) {
    const user = this.attrs.user as User | undefined;
    if (!user || !user.isVerified || !user.isVerified()) return;

    if (!items.has('identity')) return;

    const identityItem = items.get('identity') as Mithril.Vnode<{ className?: string }> | undefined;
    if (!identityItem) return;

    const badge = <VerifiedBadge user={user} className="VerifiedBadge--card" />;

    items.setContent(
      'identity',
      <h1 className={(identityItem.attrs && identityItem.attrs.className) || 'UserCard-identity'}>
        {identityItem.children as Mithril.Children} {badge}
      </h1>
    );
  });
}
