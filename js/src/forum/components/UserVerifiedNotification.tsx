import app from "flarum/forum/app";
import Notification from "flarum/forum/components/Notification";
import type Mithril from "mithril";
import type User from "flarum/common/models/User";

/**
 * Notification card shown when an admin verifies the user's account.
 * Clicking the card takes the user to their own profile, where they can
 * see the badge in the hero.
 */
export default class UserVerifiedNotification extends Notification {
  icon(): string {
    return "fas fa-certificate";
  }

  href(): string {
    // Notification.subject() returns a generic Model — for user-verified we
    // know it's the verified User and route.user() needs that concrete type.
    const subject = this.attrs.notification.subject() as
      User | null | undefined;
    return subject ? app.route.user(subject) : "";
  }

  content(): Mithril.Children {
    return app.translator.trans(
      "ramon-verified.forum.notifications.user_verified_text",
    );
  }

  excerpt(): Mithril.Children {
    return null;
  }
}
