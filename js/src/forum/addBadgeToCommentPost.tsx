import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";

import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import type ItemList from "flarum/common/utils/ItemList";

import VerifiedBadge from "../common/components/VerifiedBadge";
import { findVnodeByClass } from "./utils/vnodeTree";

/**
 * Add the verified badge as its own item in the post header item list, and
 * to the live reply preview.
 */
export default function addBadgeToCommentPost(): void {
  extend(
    "flarum/forum/components/CommentPost",
    "headerItems",
    function (this: any, items: ItemList<Mithril.Children>) {
      const post = this.attrs.post;
      const user: User | undefined = post && post.user && post.user();
      if (!user || !user.isVerified || !user.isVerified()) return;

      items.add(
        "verified",
        <VerifiedBadge user={user} className="VerifiedBadge--post" />,
        95,
      );
    },
  );

  // The live reply preview (core's ReplyPlaceholder, `<article class="Post
  // CommentPost editing">`) builds its header straight from <PostUser>, so it
  // never goes through `headerItems` above. Add the badge next to it.
  extend(
    "flarum/forum/components/ReplyPlaceholder",
    "view",
    function (vnode: Mithril.Vnode<any, any>) {
      if (!vnode || vnode.tag !== "article") return;
      const user: User | null = app.session.user;
      if (!user || !user.isVerified || !user.isVerified()) return;

      const header = findVnodeByClass(vnode, "Post-header");
      if (!header || !Array.isArray(header.children)) return;

      // Same wrapper as a real post (`ul > li.item-verified`), so the
      // header's baseline alignment and the <li> spacing apply unchanged.
      header.children = [
        ...header.children,
        <ul>
          <li className="item-verified">
            <VerifiedBadge user={user} className="VerifiedBadge--post" />
          </li>
        </ul>,
      ];
    },
  );
}
