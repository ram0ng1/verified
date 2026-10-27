import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';

const trans = (key: string) => app.translator.trans(`ramon-verified.admin.encryption.${key}`);

export interface IRegenerateConfirmAttrs extends IInternalModalAttrs {
  // `false` quando a operação falha; o modal continua aberto.
  onConfirm: () => Promise<boolean | unknown>;
}

export default class RegenerateConfirmModal extends Modal<IRegenerateConfirmAttrs> {
  protected acknowledged = false;
  protected submitting = false;

  className() {
    return 'EncryptionRegenerateModal Modal--medium';
  }

  title() {
    return trans('regenerate_modal.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Alert Alert--error">
          <p>{trans('regenerate_modal.warning')}</p>
        </div>

        <label className="EncryptionRegenerate-confirm">
          <input
            type="checkbox"
            checked={this.acknowledged}
            onchange={(e: Event) => {
              this.acknowledged = (e.target as HTMLInputElement).checked;
              m.redraw();
            }}
          />{' '}
          {trans('regenerate_modal.acknowledge')}
        </label>

        <div className="Form-group">
          <Button
            className="Button Button--primary EncryptionRegenerate-submit"
            loading={this.submitting}
            disabled={!this.acknowledged || this.submitting}
            onclick={() => this.submit()}
          >
            {trans('regenerate_modal.submit')}
          </Button>
        </div>
      </div>
    );
  }

  async submit() {
    this.submitting = true;
    m.redraw();
    try {
      const ok = await this.attrs.onConfirm();
      // Fica aberto para tentar de novo sem marcar a confirmação outra vez.
      if (ok === false) {
        this.submitting = false;
        m.redraw();
        return;
      }
      this.hide();
    } catch (e) {
      // O apiCall já mostrou o alerta de erro.
      this.submitting = false;
      m.redraw();
    }
  }
}
