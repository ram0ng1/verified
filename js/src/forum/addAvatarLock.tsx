import app from "flarum/forum/app";
import { extend, override } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import type ItemList from "flarum/common/utils/ItemList";

import {
  isLockedAvatar,
  requestAvatarChange,
  showLockedAlert,
} from "./utils/avatarLock";

/**
 * Replace the upload/remove buttons in AvatarEditor with a "locked" notice
 * for verified users, and intercept every imperative upload/remove path so
 * the avatar is truly immutable on the client.
 *
 * The backend EnforceAvatarLock listener is the actual security boundary;
 * these overrides exist purely for UX.
 */
export default function addAvatarLock(): void {
  extend(
    "flarum/forum/components/AvatarEditor",
    "controlItems",
    function (this: any, items: ItemList<Mithril.Children>) {
      if (!isLockedAvatar(this)) return;

      if (items.has("upload")) items.remove("upload");
      if (items.has("remove")) items.remove("remove");

      const user = this.attrs.user as User | undefined;

      items.add(
        "verified-locked",
        <div className="AvatarEditor-lockedNotice">
          <i className="icon fas fa-lock" />
          <span className="AvatarEditor-lockedNotice-title">
            {app.translator.trans("ramon-verified.forum.avatar.locked_label")}
          </span>
          <span className="AvatarEditor-lockedNotice-text">
            {app.translator.trans("ramon-verified.forum.avatar.locked_help")}
          </span>
          <Button
            className="Button Button--primary AvatarEditor-lockedNotice-button"
            icon="fas fa-pen"
            onclick={(e: Event) => {
              if (e) {
                e.stopPropagation();
              }
              requestAvatarChange(user);
            }}
          >
            {app.translator.trans(
              "ramon-verified.forum.avatar.request_change_button"
            )}
          </Button>
        </div>,
        100
      );
    }
  );

  override(
    "flarum/forum/components/AvatarEditor",
    "quickUpload",
    function (this: any, original: any, ...args: unknown[]) {
      const e = args[0] as Event | undefined;
      if (isLockedAvatar(this)) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        showLockedAlert();
        return;
      }
      return original(e);
    }
  );

  override(
    "flarum/forum/components/AvatarEditor",
    "openPicker",
    function (this: any, original: any) {
      if (isLockedAvatar(this)) {
        showLockedAlert();
        return;
      }
      return original();
    }
  );

  override(
    "flarum/forum/components/AvatarEditor",
    "remove",
    function (this: any, original: any) {
      if (isLockedAvatar(this)) {
        showLockedAlert();
        return;
      }
      return original();
    }
  );

  override(
    "flarum/forum/components/AvatarEditor",
    "dropUpload",
    function (this: any, original: any, ...args: unknown[]) {
      const e = args[0] as Event | undefined;
      if (isLockedAvatar(this)) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        showLockedAlert();
        return;
      }
      return original(e);
    }
  );
}
