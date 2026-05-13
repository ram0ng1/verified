import app from "flarum/admin/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import extractText from "flarum/common/utils/extractText";

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.encryption.${key}`, params ?? {});

export interface IKeypairRevealAttrs extends IInternalModalAttrs {
  privateKey: string;
  configKey: string;
  orphanedDocuments?: number;
}

/**
 * One-shot dialog that shows the freshly generated private key. Modeled
 * line-for-line on Flarum core's `ResetExtensionSettingsModal`: no
 * lifecycle overrides, no callback plumbing — just `this.hide()` on
 * close. The parent card refreshes its state BEFORE calling
 * `app.modal.show(...)`, so by the time the user dismisses (X / Esc /
 * backdrop / Close button) the panel underneath is already accurate.
 */
export default class KeypairRevealModal extends Modal<IKeypairRevealAttrs> {
  protected copied = false;

  className() {
    return "EncryptionRevealModal Modal--medium";
  }

  title() {
    return trans("reveal_modal.title");
  }

  content() {
    const { privateKey, configKey, orphanedDocuments = 0 } = this.attrs;
    const snippet = `'${configKey}' => '${privateKey}',`;

    return (
      <div className="Modal-body">
        <p>{trans("reveal_modal.intro")}</p>

        {orphanedDocuments > 0 && (
          <div className="Alert Alert--warning EncryptionReveal-orphaned">
            {trans("reveal_modal.orphaned", { count: orphanedDocuments })}
          </div>
        )}

        <div className="EncryptionReveal-warning Alert Alert--error">
          <strong>{trans("reveal_modal.warning_title")}</strong>
          <p>{trans("reveal_modal.warning_body")}</p>
        </div>

        <label className="EncryptionReveal-label">
          {trans("reveal_modal.snippet_label")}
        </label>
        <pre className="EncryptionReveal-snippet">
          <code>{snippet}</code>
        </pre>

        <div className="Form-group EncryptionReveal-actions">
          <Button
            className="Button"
            icon="fas fa-copy"
            onclick={() => this.copy(snippet)}
          >
            {this.copied
              ? trans("reveal_modal.copied")
              : trans("reveal_modal.copy_button")}
          </Button>
          <Button
            className="Button Button--primary"
            onclick={() => this.hide()}
          >
            {trans("reveal_modal.close")}
          </Button>
        </div>
      </div>
    );
  }

  copy(snippet: string) {
    if (!navigator.clipboard) {
      app.alerts.show(
        { type: "error" },
        extractText(app.translator.trans("ramon-verified.lib.errors.clipboard"))
      );
      return;
    }
    navigator.clipboard.writeText(snippet).then(
      () => {
        this.copied = true;
        m.redraw();
        setTimeout(() => {
          this.copied = false;
          m.redraw();
        }, 2000);
      },
      () => {
        app.alerts.show(
          { type: "error" },
          extractText(
            app.translator.trans("ramon-verified.lib.errors.clipboard")
          )
        );
      }
    );
  }
}
