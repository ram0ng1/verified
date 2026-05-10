import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';

import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';

import { performVerification } from './utils/verifyUser';

/**
 * Add "Verify" / "Revoke verification" actions to the moderation dropdown.
 * The button shown depends on whether the target user is currently verified.
 */
export default function addVerificationUserControls(): void {
  extend(UserControls, 'moderationControls', function (items: ItemList<Mithril.Children>, user: User) {
    if (!app.forum.attribute('canVerifyUsers')) return;

    const isVerified = user.isVerified && user.isVerified();

    if (isVerified) {
      items.add(
        'verifiedRevoke',
        <Button icon="fas fa-ban" onclick={() => performVerification(user, 'revoke')}>
          {app.translator.trans('ramon-verified.forum.user_controls.revoke_button')}
        </Button>,
        50
      );
    } else {
      items.add(
        'verifiedVerify',
        <Button icon="fas fa-certificate" onclick={() => performVerification(user, 'verify')}>
          {app.translator.trans('ramon-verified.forum.user_controls.verify_button')}
        </Button>,
        50
      );
    }
  });
}
