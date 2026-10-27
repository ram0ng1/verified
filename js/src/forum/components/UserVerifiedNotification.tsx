import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';

export default class UserVerifiedNotification extends Notification {
  icon(): string {
    return 'fas fa-certificate';
  }

  href(): string {
    // subject() devolve um Model genérico; aqui é sempre o User verificado.
    const subject = this.attrs.notification.subject() as User | null | undefined;
    return subject ? app.route.user(subject) : '';
  }

  content(): Mithril.Children {
    return app.translator.trans('ramon-verified.forum.notifications.user_verified_text');
  }

  excerpt(): Mithril.Children {
    return null;
  }
}
