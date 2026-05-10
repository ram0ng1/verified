import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type User from 'flarum/common/models/User';

import apiCall from '../../common/utils/apiCall';
import promptTier from '../../common/utils/promptTier';

export type VerificationAction = 'verify' | 'revoke';

const HTTP_METHOD: Record<VerificationAction, 'POST' | 'DELETE'> = {
  verify: 'POST',
  revoke: 'DELETE',
};

const PROMPT_KEY: Record<VerificationAction, string> = {
  verify: 'verify_prompt',
  revoke: 'revoke_prompt',
};

const SUCCESS_KEY: Record<VerificationAction, string> = {
  verify: 'verify_success',
  revoke: 'revoke_success',
};

const ERROR_KEY: Record<VerificationAction, string> = {
  verify: 'ramon-verified.forum.user_controls.verify_failed',
  revoke: 'ramon-verified.forum.user_controls.revoke_failed',
};

/**
 * Run a verify or revoke action against a user. Prompts the admin for tier
 * (verify only) and a note, then PATCHes the user model in place so any
 * mounted component reflecting the user reactively redraws.
 */
export async function performVerification(user: User, action: VerificationAction): Promise<void> {
  let tierId: string | null = null;
  if (action === 'verify') {
    const tier = promptTier();
    if (!tier) return;
    tierId = tier.id;
  }

  const note = window.prompt(
    extractText(app.translator.trans('ramon-verified.forum.user_controls.' + PROMPT_KEY[action]))
  );
  if (note === null) return;

  const body: Record<string, unknown> = { adminNote: note || '' };
  if (tierId) body.tier = tierId;

  const res = await apiCall<{ data?: { attributes?: Record<string, unknown> } }>(
    {
      method: HTTP_METHOD[action],
      url: app.forum.attribute('apiUrl') + '/verified/users/' + user.id() + '/verify',
      body,
    },
    { errorKey: ERROR_KEY[action] }
  );

  if (!res) {
    m.redraw();
    return;
  }

  if (res.data && res.data.attributes) {
    user.pushAttributes(res.data.attributes);
  } else {
    user.pushAttributes({
      isVerified: action === 'verify',
      verifiedAt: action === 'verify' ? new Date().toISOString() : null,
    });
  }

  app.alerts.show(
    { type: 'success' },
    app.translator.trans('ramon-verified.forum.user_controls.' + SUCCESS_KEY[action])
  );
  m.redraw();
}
