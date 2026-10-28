import Extend from 'flarum/common/extenders';
import commonExtend from '../common/extend';
import UserVerifiedNotification from './components/UserVerifiedNotification';

// A notificação fica só no forum: o AdminApplication não tem `notificationComponents`.
export default [...commonExtend, new Extend.Notification().add('userVerified', UserVerifiedNotification)];
