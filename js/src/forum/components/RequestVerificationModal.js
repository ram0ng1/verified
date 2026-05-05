import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Form from 'flarum/common/components/Form';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';

export default class RequestVerificationModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    this.reason = Stream('');
    this.documentType = Stream('rg');
    this.documentPath = Stream('');
    this.fileName = Stream('');
    this.uploading = false;
    this.uploadError = null;
  }

  className() {
    return 'VerifiedRequestModal Modal--small';
  }

  title() {
    return app.translator.trans('ramon-verified.forum.request_modal.title');
  }

  content() {
    const requireDoc = !!app.forum.attribute('ramonVerifiedRequireDocument');

    return (
      <div className="Modal-body">
        <Form className="Form">
          <p className="helpText">{app.translator.trans('ramon-verified.forum.request_modal.intro')}</p>

          <div className="Form-group">
            <label>{app.translator.trans('ramon-verified.forum.request_modal.reason_label')}</label>
            <textarea
              className="FormControl"
              rows="4"
              maxlength="1000"
              placeholder={extractText(app.translator.trans('ramon-verified.forum.request_modal.reason_placeholder'))}
              bidi={this.reason}
              disabled={this.loading || this.uploading}
            />
          </div>

          {requireDoc ? this.documentFields() : null}

          <div className="Form-group Form-controls">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={this.uploading || (requireDoc && !this.documentPath())}
            >
              {app.translator.trans('ramon-verified.forum.request_modal.submit_button')}
            </Button>
          </div>
        </Form>
      </div>
    );
  }

  documentFields() {
    return [
      <div className="Form-group">
        <label>{app.translator.trans('ramon-verified.forum.request_modal.document_type_label')}</label>
        <select
          className="FormControl"
          value={this.documentType()}
          onchange={(e) => this.documentType(e.target.value)}
          disabled={this.loading || this.uploading}
        >
          <option value="rg">{app.translator.trans('ramon-verified.forum.request_modal.document_type_rg')}</option>
          <option value="cpf">{app.translator.trans('ramon-verified.forum.request_modal.document_type_cpf')}</option>
          <option value="passport">{app.translator.trans('ramon-verified.forum.request_modal.document_type_passport')}</option>
          <option value="driver">{app.translator.trans('ramon-verified.forum.request_modal.document_type_driver')}</option>
          <option value="other">{app.translator.trans('ramon-verified.forum.request_modal.document_type_other')}</option>
        </select>
      </div>,
      <div className="Form-group">
        <label>{app.translator.trans('ramon-verified.forum.request_modal.document_label')}</label>
        <input
          type="file"
          className="FormControl"
          accept="image/png,image/jpeg,image/webp,application/pdf"
          onchange={(e) => this.uploadDocument(e.target.files && e.target.files[0])}
          disabled={this.loading || this.uploading}
        />
        {this.uploading ? <p className="helpText">{app.translator.trans('ramon-verified.forum.request_modal.uploading')}</p> : null}
        {this.fileName() && !this.uploading ? (
          <p className="helpText VerifiedRequestModal-filename">
            {app.translator.trans('ramon-verified.forum.request_modal.uploaded', { name: this.fileName() })}
          </p>
        ) : null}
        {this.uploadError ? <p className="helpText VerifiedRequestModal-error">{this.uploadError}</p> : null}
        <p className="helpText">{app.translator.trans('ramon-verified.forum.request_modal.document_help')}</p>
      </div>,
    ];
  }

  uploadDocument(file) {
    if (!file) return;

    const MAX = 8 * 1024 * 1024;
    if (file.size > MAX) {
      this.uploadError = extractText(app.translator.trans('ramon-verified.forum.request_modal.file_too_large'));
      m.redraw();
      return;
    }

    const allowed = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];
    if (file.type && allowed.indexOf(file.type) === -1) {
      this.uploadError = extractText(app.translator.trans('ramon-verified.forum.request_modal.bad_type'));
      m.redraw();
      return;
    }

    this.uploading = true;
    this.uploadError = null;
    this.documentPath('');
    this.fileName('');
    m.redraw();

    const body = new FormData();
    body.append('document', file);

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/verified/documents',
        serialize: (raw) => raw,
        body,
      })
      .then((res) => {
        this.uploading = false;
        if (res && res.documentPath) {
          this.documentPath(res.documentPath);
          this.fileName(file.name || '');
        } else {
          this.uploadError = extractText(app.translator.trans('ramon-verified.forum.request_modal.upload_failed'));
        }
        m.redraw();
      })
      .catch((err) => {
        this.uploading = false;
        const msg =
          (err && err.response && err.response.errors && err.response.errors[0] && err.response.errors[0].detail) ||
          extractText(app.translator.trans('ramon-verified.forum.request_modal.upload_failed'));
        this.uploadError = msg;
        m.redraw();
      });
  }

  onsubmit(e) {
    e.preventDefault();

    if (this.uploading) return;

    const requireDoc = !!app.forum.attribute('ramonVerifiedRequireDocument');
    if (requireDoc && !this.documentPath()) {
      this.uploadError = extractText(app.translator.trans('ramon-verified.forum.request_modal.document_required'));
      m.redraw();
      return;
    }

    this.loading = true;

    const data = {
      reason: this.reason(),
    };

    if (this.documentPath()) {
      data.documentType = this.documentType();
      data.documentPath = this.documentPath();
    }

    app.store
      .createRecord('verification-requests')
      .save(data, { errorHandler: this.onerror.bind(this) })
      .then(() => {
        // Update the user model so the settings page reflects the change.
        if (app.session.user) {
          app.session.user.pushAttributes({ hasPendingVerificationRequest: true, canRequestVerification: false });
        }
        app.alerts.show({ type: 'success' }, app.translator.trans('ramon-verified.forum.request_modal.success'));
        this.hide();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
