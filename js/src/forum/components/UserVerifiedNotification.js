import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

/**
 * Notification card shown when an admin verifies the user's account.
 * Clicking the card takes the user to their own profile, where they can
 * see the badge in the hero.
 */
export default class UserVerifiedNotification extends Notification {
  icon() {
    return 'fas fa-certificate';
  }

  href() {
    const subject = this.attrs.notification.subject();
    return subject ? app.route.user(subject) : '';
  }

  content() {
    return app.translator.trans('ramon-verified.forum.notifications.user_verified_text');
  }

  excerpt() {
    return null;
  }
}
