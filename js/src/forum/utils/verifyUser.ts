import app from "flarum/forum/app";
import type User from "flarum/common/models/User";

import apiCall from "../../common/utils/apiCall";
import verificationPrompt from "../../common/utils/verificationPrompt";
import { getConfiguredTiers } from "../../common/utils/tiers";

export type VerificationAction = "verify" | "revoke";

const HTTP_METHOD: Record<VerificationAction, "POST" | "DELETE"> = {
  verify: "POST",
  revoke: "DELETE",
};

const NOTE_KEY: Record<VerificationAction, string> = {
  verify: "verify_prompt",
  revoke: "revoke_prompt",
};

const TITLE_KEY: Record<VerificationAction, string> = {
  verify: "verify_button",
  revoke: "revoke_button",
};

const SUCCESS_KEY: Record<VerificationAction, string> = {
  verify: "verify_success",
  revoke: "revoke_success",
};

const ERROR_KEY: Record<VerificationAction, string> = {
  verify: "ramon-verified.forum.user_controls.verify_failed",
  revoke: "ramon-verified.forum.user_controls.revoke_failed",
};

const uc = (key: string) =>
  app.translator.trans("ramon-verified.forum.user_controls." + key);

/**
 * Run a verify or revoke action against a user. Opens a modal asking for an
 * audit-log note (and, for verify, the tier), then PATCHes the user model in
 * place so any mounted component reflecting the user reactively redraws.
 */
export async function performVerification(
  user: User,
  action: VerificationAction,
): Promise<void> {
  const withTier = action === "verify";

  // Preserve the previous "no tiers configured" guard — opening the modal to
  // pick from an empty list would be a dead end.
  if (withTier && getConfiguredTiers().length === 0) {
    app.alerts.show(
      { type: "error" },
      app.translator.trans("ramon-verified.lib.tier_prompt.no_tiers"),
    );
    return;
  }

  const result = await verificationPrompt({
    title: uc(TITLE_KEY[action]),
    noteLabel: uc(NOTE_KEY[action]),
    confirmLabel: uc(TITLE_KEY[action]),
    withTier,
  });
  if (!result) return;

  const body: Record<string, unknown> = { adminNote: result.note };
  if (result.tier) body.tier = result.tier;

  const res = await apiCall<{
    data?: { attributes?: Record<string, unknown> };
  }>(
    {
      method: HTTP_METHOD[action],
      url:
        app.forum.attribute("apiUrl") +
        "/verified/users/" +
        user.id() +
        "/verify",
      body,
    },
    { errorKey: ERROR_KEY[action] },
  );

  if (!res) {
    m.redraw();
    return;
  }

  if (res.data && res.data.attributes) {
    user.pushAttributes(res.data.attributes);
  } else {
    user.pushAttributes({
      isVerified: action === "verify",
      verifiedAt: action === "verify" ? new Date().toISOString() : null,
    });
  }

  app.alerts.show(
    { type: "success" },
    app.translator.trans(
      "ramon-verified.forum.user_controls." + SUCCESS_KEY[action],
    ),
  );
  m.redraw();
}
