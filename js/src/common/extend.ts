import Extend from 'flarum/common/extenders';
import User from 'flarum/common/models/User';
import VerificationRequest from './models/VerificationRequest';

/**
 * Extenders shared by both the forum and admin apps. Things that only make
 * sense on one side (notification-component mapping, route additions, etc.)
 * live in the per-app `extend.ts` files.
 */
export default [
  new Extend.Store().add('verification-requests', VerificationRequest),

  new Extend.Model(User)
    .attribute<boolean>('isVerified')
    .attribute<Date | null, string | null>('verifiedAt', (val) => (val ? new Date(val) : null))
    .attribute<boolean>('canRequestVerification')
    .attribute<boolean>('hasPendingVerificationRequest')
    .attribute<boolean>('isAvatarLocked'),
];
