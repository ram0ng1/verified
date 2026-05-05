import Model from 'flarum/common/Model';
import mixin from 'flarum/common/utils/mixin';

export default class VerificationRequest extends mixin(Model, {
  status: Model.attribute('status'),
  documentType: Model.attribute('documentType'),
  documentPath: Model.attribute('documentPath'),
  reason: Model.attribute('reason'),
  adminNote: Model.attribute('adminNote'),
  createdAt: Model.attribute('createdAt', Model.transformDate),
  updatedAt: Model.attribute('updatedAt', Model.transformDate),
  handledAt: Model.attribute('handledAt', Model.transformDate),
  user: Model.hasOne('user'),
  handler: Model.hasOne('handler'),
}) {
  apiEndpoint() {
    return '/verification-requests' + (this.exists ? '/' + this.data.id : '');
  }

  isPending() {
    return this.status() === 'pending';
  }

  isApproved() {
    return this.status() === 'approved';
  }

  isRejected() {
    return this.status() === 'rejected';
  }
}
