import app from "flarum/admin/app";
import Extend from "flarum/common/extenders";

import commonExtend from "../common/extend";
import LazyVerifiedSettingsPanel from "./components/LazyVerifiedSettingsPanel";

// O auto-verificado não tem permissão própria: cada tier escolhe, em
// Admin → Tiers, os grupos que recebem o selo sem pedido.
export default [
  ...commonExtend,

  new Extend.Admin()
    .customSetting(() => <LazyVerifiedSettingsPanel />, 100)
    .permission(
      () => ({
        icon: "fas fa-certificate",
        label: app.translator.trans(
          "ramon-verified.admin.permissions.request_label",
        ),
        permission: "verified.request",
      }),
      "start",
    )
    .permission(
      () => ({
        icon: "fas fa-user-check",
        label: app.translator.trans(
          "ramon-verified.admin.permissions.verify_users_label",
        ),
        permission: "verified.verifyUsers",
      }),
      "moderate",
    )
    .permission(
      () => ({
        icon: "fas fa-ban",
        label: app.translator.trans(
          "ramon-verified.admin.permissions.self_revoke_label",
        ),
        permission: "verified.selfRevoke",
      }),
      "start",
    ),
];
