import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import type Mithril from "mithril";
import type ItemList from "flarum/common/utils/ItemList";

import VerifiedBadge from "../common/components/VerifiedBadge";
import RequestVerificationModal from "./components/RequestVerificationModal";

/**
 * Inject either the verified-status pill, the pending pill, or the "request
 * verification" button into the user's account settings page, depending on
 * the user's current verification state.
 */
export default function addVerificationRequestButton(): void {
  extend(
    "flarum/forum/components/SettingsPage",
    "accountItems",
    function (this: any, items: ItemList<Mithril.Children>) {
      const user = app.session.user;
      if (!user) return;

      if (user.isVerified && user.isVerified()) {
        items.add(
          "verifiedStatus",
          <button
            type="button"
            className="Button VerifiedSettings-pill VerifiedSettings-pill--verified"
            disabled
          >
            <span className="Button-icon VerifiedSettings-pill-icon">
              <VerifiedBadge user={user} plain />
            </span>
            <span className="Button-label">
              {app.translator.trans(
                "ramon-verified.forum.settings.verified_label"
              )}
            </span>
          </button>,
          80
        );
        return;
      }

      if (
        user.hasPendingVerificationRequest &&
        user.hasPendingVerificationRequest()
      ) {
        items.add(
          "verifiedPending",
          <button
            type="button"
            className="Button VerifiedSettings-pill VerifiedSettings-pill--pending"
            disabled
          >
            <i className="icon fas fa-hourglass-half Button-icon" />
            <span className="Button-label">
              {app.translator.trans(
                "ramon-verified.forum.settings.pending_label"
              )}
            </span>
          </button>,
          80
        );
        return;
      }

      if (user.canRequestVerification && user.canRequestVerification()) {
        items.add(
          "verifiedRequest",
          <div className="Form-group">
            <Button
              className="Button"
              icon="fas fa-certificate"
              onclick={() => app.modal.show(RequestVerificationModal)}
            >
              {app.translator.trans(
                "ramon-verified.forum.settings.request_button"
              )}
            </Button>
          </div>,
          80
        );
      }
    }
  );
}
