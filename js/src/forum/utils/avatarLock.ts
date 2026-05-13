import app from "flarum/forum/app";
import extractText from "flarum/common/utils/extractText";
import type User from "flarum/common/models/User";

/**
 * Whether the avatar editor is currently rendering for a user whose avatar is
 * locked by an admin. The backend EnforceAvatarLock listener is the actual
 * security boundary; this helper exists purely for UX to short-circuit the
 * upload/remove flows on the client.
 */
export function isLockedAvatar(component: {
  attrs?: { user?: User };
}): boolean {
  const user = component.attrs && component.attrs.user;
  return !!(user && user.isAvatarLocked && user.isAvatarLocked());
}

export function showLockedAlert(): void {
  app.alerts.show(
    { type: "error" },
    app.translator.trans("ramon-verified.forum.avatar.locked_alert")
  );
}

/**
 * Verified-user flow for changing the locked avatar:
 *  1. Confirm — make it explicit that the verification will be revoked.
 *  2. Self-revoke verification via DELETE /verified/users/{id}/verify.
 *  3. Push the new state into the local user model so the avatar editor
 *     unlocks immediately.
 */
export function requestAvatarChange(user: User | null | undefined): void {
  if (!user || !user.id) return;

  const confirmText = extractText(
    app.translator.trans("ramon-verified.forum.avatar.request_change_confirm")
  );
  if (!window.confirm(confirmText)) return;

  app
    .request<{ data?: { attributes?: Record<string, unknown> } }>({
      method: "DELETE",
      url:
        app.forum.attribute("apiUrl") +
        "/verified/users/" +
        user.id() +
        "/verify",
      body: {},
    })
    .then((res) => {
      if (res && res.data && res.data.attributes) {
        user.pushAttributes(res.data.attributes);
      } else {
        user.pushAttributes({ isVerified: false, verifiedAt: null });
      }
      user.pushAttributes({ isAvatarLocked: false });

      app.alerts.show(
        { type: "success" },
        app.translator.trans(
          "ramon-verified.forum.avatar.request_change_success"
        )
      );
      m.redraw();
    })
    .catch(() => {
      app.alerts.show(
        { type: "error" },
        app.translator.trans(
          "ramon-verified.forum.avatar.request_change_failed"
        )
      );
    });
}
