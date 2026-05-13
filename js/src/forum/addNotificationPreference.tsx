import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";

import type ItemList from "flarum/common/utils/ItemList";

interface NotificationType {
  name: string;
  icon: string;
  label: unknown;
}

/**
 * Expose the `userVerified` notification toggle in the user's preferences page.
 */
export default function addNotificationPreference(): void {
  extend(
    "flarum/forum/components/NotificationGrid",
    "notificationTypes",
    function (this: any, items: ItemList<NotificationType>) {
      items.add("userVerified", {
        name: "userVerified",
        icon: "fas fa-certificate",
        label: app.translator.trans(
          "ramon-verified.forum.notifications.user_verified_preference"
        ),
      });
    }
  );
}
