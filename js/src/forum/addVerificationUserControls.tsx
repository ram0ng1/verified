import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";
import UserControls from "flarum/forum/utils/UserControls";

import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import type ItemList from "flarum/common/utils/ItemList";

import { performVerification } from "./utils/verifyUser";

/**
 * Add "Verify" / "Revoke verification" actions to the moderation dropdown.
 *
 * Gating mirrors `VerifyUserController::handle` (§3, §4):
 *  - `canVerifyUsers` (admin/moderation): mostra Verify + Revoke contra
 *    qualquer usuário.
 *  - `canSelfRevokeVerification` sem `canVerifyUsers`: mostra apenas o
 *    Revoke, e somente quando o alvo é o próprio ator (impede o botão de
 *    aparecer no menu do perfil de outro usuário onde a ação seria 403).
 */
export default function addVerificationUserControls(): void {
  extend(
    UserControls,
    "moderationControls",
    function (items: ItemList<Mithril.Children>, user: User) {
      const canVerifyUsers = !!app.forum.attribute("canVerifyUsers");
      const canSelfRevoke = !!app.forum.attribute("canSelfRevokeVerification");

      const actor = app.session.user;
      const isSelf = !!(actor && String(actor.id()) === String(user.id()));
      const isVerified = !!(user.isVerified && user.isVerified());

      if (canVerifyUsers) {
        if (isVerified) {
          items.add(
            "verifiedRevoke",
            <Button
              icon="fas fa-ban"
              onclick={() => performVerification(user, "revoke")}
            >
              {app.translator.trans(
                "ramon-verified.forum.user_controls.revoke_button",
              )}
            </Button>,
            50,
          );
        } else {
          items.add(
            "verifiedVerify",
            <Button
              icon="fas fa-certificate"
              onclick={() => performVerification(user, "verify")}
            >
              {app.translator.trans(
                "ramon-verified.forum.user_controls.verify_button",
              )}
            </Button>,
            50,
          );
        }
        return;
      }

      if (isSelf && isVerified && canSelfRevoke) {
        items.add(
          "verifiedRevoke",
          <Button
            icon="fas fa-ban"
            onclick={() => performVerification(user, "revoke")}
          >
            {app.translator.trans(
              "ramon-verified.forum.user_controls.revoke_button",
            )}
          </Button>,
          50,
        );
      }
    },
  );
}
