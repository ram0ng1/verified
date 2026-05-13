import { extend } from "flarum/common/extend";

import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import type ItemList from "flarum/common/utils/ItemList";

import VerifiedBadge from "../common/components/VerifiedBadge";

/**
 * Append a verified badge inside the identity h1 of UserCard.
 *
 * The same UserCard component renders both the full profile hero
 * (`className="Hero UserHero"`) and the floating hover card you see when
 * mousing over a username in a discussion list (`className="UserCard--popover"`).
 * The hover card IS itself a popover, so nesting our own VerifiedPopover
 * inside it would stack two popovers — there we render the bare badge with
 * a simple title tooltip. On the profile hero we want the rich popover.
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
        | Mithril.Vnode<{ className?: string }>
        | undefined;
      if (!identityItem) return;

      const cardClass = String(this.attrs.className || "");
      const isHoverPopover = cardClass.indexOf("UserCard--popover") !== -1;

      const badge = (
        <VerifiedBadge
          user={user}
          className="VerifiedBadge--card"
          plain={isHoverPopover}
        />
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
        </h1>
      );
    }
  );
}
