import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';

import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type ItemList from 'flarum/common/utils/ItemList';

import { performVerification } from './utils/verifyUser';

/**
 * Espelha as regras do `VerifyUserController`: quem só pode autorrevogar vê o
 * botão apenas no próprio perfil, já que em outro usuário a ação daria 403.
 */
export default function addVerificationUserControls(): void {
  extend(UserControls, 'moderationControls', function (items: ItemList<Mithril.Children>, user: User) {
    const canVerifyUsers = !!app.forum.attribute('canVerifyUsers');
    const canSelfRevoke = !!app.forum.attribute('canSelfRevokeVerification');

    const actor = app.session.user;
    const isSelf = !!(actor && String(actor.id()) === String(user.id()));
    const isVerified = !!(user.isVerified && user.isVerified());

    if (canVerifyUsers) {
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
      return;
    }

    if (isSelf && isVerified && canSelfRevoke) {
      items.add(
        'verifiedRevoke',
        <Button icon="fas fa-ban" onclick={() => performVerification(user, 'revoke')}>
          {app.translator.trans('ramon-verified.forum.user_controls.revoke_button')}
        </Button>,
        50
      );
    }
  });
}
