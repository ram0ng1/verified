import Extend from 'flarum/common/extenders';
import User from 'flarum/common/models/User';
import VerificationRequest from './models/VerificationRequest';
import UserVerifiedNotification from '../forum/components/UserVerifiedNotification';

export default [
  new Extend.Store().add('verification-requests', VerificationRequest),

  new Extend.Model(User)
    .attribute('isVerified')
    .attribute('verifiedAt', (val) => (val ? new Date(val) : null))
    .attribute('canRequestVerification')
    .attribute('hasPendingVerificationRequest')
    .attribute('isAvatarLocked'),

  // Maps the `userVerified` notification type from the backend blueprint
  // to its forum-side renderer.
  new Extend.Notification().add('userVerified', UserVerifiedNotification),
];
