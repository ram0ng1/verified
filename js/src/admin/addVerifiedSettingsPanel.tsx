import app from "flarum/admin/app";

import VerifiedSettingsPanel from "./components/VerifiedSettingsPanel";

const EXT_ID = "ramon-verified";

/**
 * Wire the extension's admin UI: settings panel + the two permissions
 * surfaced in the standard PermissionGrid.
 *
 * Note: `verified.autoVerified` is NOT registered here. Auto-verification is
 * per-tier — each tier in Admin → Tiers picks the groups that get its badge
 * automatically.
 */
export default function addVerifiedSettingsPanel(): void {
  app.registry
    .for(EXT_ID)
    .registerSetting(
      () => <VerifiedSettingsPanel />,
      100,
      "ramon-verified.panel"
    )
    .registerPermission(
      {
        icon: "fas fa-certificate",
        label: app.translator.trans(
          "ramon-verified.admin.permissions.request_label"
        ),
        permission: "verified.request",
      },
      "start"
    )
    .registerPermission(
      {
        icon: "fas fa-user-check",
        label: app.translator.trans(
          "ramon-verified.admin.permissions.verify_users_label"
        ),
        permission: "verified.verifyUsers",
      },
      "moderate"
    );
}
