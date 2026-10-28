import app from 'flarum/forum/app';

import addBadgeToUserCard from './addBadgeToUserCard';
import addBadgeToCommentPost from './addBadgeToCommentPost';
import addVerificationRequestButton from './addVerificationRequestButton';
import addVerificationUserControls from './addVerificationUserControls';
import addAvatarLock from './addAvatarLock';
import addNotificationPreference from './addNotificationPreference';
import addAvocadoIntegration from './addAvocadoIntegration';

export { default as extend } from './extend';

// Prioridade -100 para rodar depois do Avocado (0), que substitui
// UserPage.prototype.view; precisamos envolver a view dele, não a do core.
app.initializers.add(
  'ramon-verified',
  () => {
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
