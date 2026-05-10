import app from 'flarum/forum/app';

import installGlobalErrorHandler from '../common/utils/installGlobalErrorHandler';
import addBadgeToUserCard from './addBadgeToUserCard';
import addBadgeToCommentPost from './addBadgeToCommentPost';
import addVerificationRequestButton from './addVerificationRequestButton';
import addVerificationUserControls from './addVerificationUserControls';
import addAvatarLock from './addAvatarLock';
import addNotificationPreference from './addNotificationPreference';
import addAvocadoIntegration from './addAvocadoIntegration';

export { default as extend } from './extend';

// Use a low priority (-100) so this initializer runs AFTER avocado's
// (priority 0). Avocado replaces UserPage.prototype.view with its own
// hero-rendering view; we need to wrap THAT view to inject our badge.
app.initializers.add(
  'ramon-verified',
  () => {
    installGlobalErrorHandler();

    addBadgeToUserCard();
    addBadgeToCommentPost();
    addVerificationRequestButton();
    addVerificationUserControls();
    addAvatarLock();
    addNotificationPreference();
    addAvocadoIntegration();
  },
  -100
);
