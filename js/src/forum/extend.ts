import Extend from 'flarum/common/extenders';
import commonExtend from '../common/extend';
import UserVerifiedNotification from './components/UserVerifiedNotification';

/**
 * Forum-side extenders. Includes everything in `common/extend.ts` plus the
 * `userVerified` notification mapping (which would crash on admin, since
 * AdminApplication doesn't have a `notificationComponents` map).
 */
export default [
  ...commonExtend,

  new Extend.Notification().add('userVerified', UserVerifiedNotification),
];
