import { extend } from "flarum/common/extend";

import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import type ItemList from "flarum/common/utils/ItemList";

import VerifiedBadge from "../common/components/VerifiedBadge";

/**
 * Append a verified badge inside the identity h1 of UserCard.
 *
 * Always renders the rich popover (gated only by the `show_tooltip` admin
 * setting). Earlier versions forced `plain` mode inside the
 * `UserCard--popover` hover card to avoid stacking two popovers, but that
 * silently downgraded the badge to a native `title="<tier>"` tooltip even
 * when the admin had the rich popover enabled — visibly inconsistent with
 * every other surface (profile hero, post header, Avocado cards). Letting
 * the popover open inside the hover card stacks visually but functionally
 * matches the configured rule.
 */
export default function addBadgeToUserCard(): void {
  extend(
    "flarum/forum/components/UserCard",
    "contentItems",
    function (this: any, items: ItemList<Mithril.Children>) {
      const user = this.attrs.user as User | undefined;
      if (!user || !user.isVerified || !user.isVerified()) return;

      if (!items.has("identity")) return;

      const identityItem = items.get("identity") as
        Mithril.Vnode<{ className?: string }> | undefined;
      if (!identityItem) return;

      const badge = (
        <VerifiedBadge user={user} className="VerifiedBadge--card" />
      );

      items.setContent(
        "identity",
        <h1
          className={
            (identityItem.attrs && identityItem.attrs.className) ||
            "UserCard-identity"
          }
        >
          {identityItem.children as Mithril.Children} {badge}
        </h1>,
      );
    },
  );
}
