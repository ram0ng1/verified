import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import EncryptionState, {
  EncryptionStatus,
  KeypairResult,
} from "../states/EncryptionState";
import KeypairRevealModal from "./KeypairRevealModal";
import RegenerateConfirmModal from "./RegenerateConfirmModal";

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.encryption.${key}`, params ?? {});

const CONFIG_KEY = "verified-private-key";

export default class EncryptionCard extends Component<ComponentAttrs> {
  protected encryption!: EncryptionState;
  protected publicCopied = false;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.encryption = new EncryptionState();
    this.encryption.refresh();
  }

  view() {
    return (
      <section className="VerifiedAdmin-card EncryptionCard">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans("section_title")}</h3>
          <p className="helpText">{trans("section_help")}</p>
        </header>

        {this.encryption.loading ? <LoadingIndicator /> : this.body()}
      </section>
    );
  }

  body() {
    const s = this.encryption.status;
    if (!s) {
      return <p className="helpText">{trans("status.unknown")}</p>;
    }

    if (!s.available) {
      return (
        <div className="Alert Alert--error">
          {trans("status.libsodium_missing")}
        </div>
      );
    }

    return (
      <>
        <div className="EncryptionCard-status">
          {this.statusBadge("public", s.has_public_key)}
          {this.statusBadge("private", s.private_key_present)}
        </div>

        {s.healthy && (
          <div className="Alert Alert--success EncryptionCard-msg">
            {trans("status.healthy")}
          </div>
        )}

        {!s.has_public_key && !s.private_key_present && (
          <div className="EncryptionCard-msg">
            <p className="helpText">{trans("status.not_setup")}</p>
            <Button
              className="Button Button--primary"
              icon="fas fa-key"
              onclick={() => this.generate()}
            >
              {trans("actions.generate")}
            </Button>
          </div>
        )}

        {/* Keys mismatch — both halves are present but they are not from
            the same pair. Existing encrypted documents are unreadable. */}
        {s.has_public_key &&
          s.private_key_present &&
          s.keys_match === false && (
            <div className="Alert Alert--error EncryptionCard-msg">
              <strong>{trans("status.mismatch_title")}</strong>
              <p>{trans("status.mismatch_body")}</p>
              <p>
                <code>'{CONFIG_KEY}'</code>
              </p>
            </div>
          )}

        {/* Private key absent. */}
        {s.has_public_key && !s.private_key_present && (
          <div className="Alert Alert--error EncryptionCard-msg">
            <strong>{trans("status.private_missing_title")}</strong>
            <p>{trans("status.private_missing_body")}</p>
            <p>
              <code>'{CONFIG_KEY}'</code>
            </p>
          </div>
        )}

        {s.has_public_key && this.publicKeyPanel(s.public_key || "", s.healthy)}
      </>
    );
  }

  publicKeyPanel(publicKey: string, healthy: boolean) {
    return (
      <div className="EncryptionCard-publicKey">
        <label className="EncryptionCard-publicKeyLabel">
          {trans("public_key.label")}
        </label>
        <div className="EncryptionCard-publicKeyRow">
          <pre className="EncryptionCard-publicKeyValue">
            <code>{publicKey}</code>
          </pre>
          <Button
            className="Button Button--icon"
            icon="fas fa-copy"
            title={extractText(trans("public_key.copy_title"))}
            aria-label={extractText(trans("public_key.copy_title"))}
            onclick={() => this.copyPublicKey(publicKey)}
          >
            {this.publicCopied ? extractText(trans("public_key.copied")) : ""}
          </Button>
        </div>

        <p className="helpText">
          {healthy
            ? trans("public_key.help_healthy")
            : trans("public_key.help_broken")}
        </p>

        <Button
          className="Button Button--danger EncryptionCard-rotateBtn"
          icon="fas fa-rotate"
          onclick={() => this.openRegenerate()}
        >
          {trans("public_key.remove_button")}
        </Button>
      </div>
    );
  }

  statusBadge(kind: "public" | "private", present: boolean) {
    return (
      <div
        className={`EncryptionCard-badge EncryptionCard-badge--${
          present ? "ok" : "missing"
        }`}
      >
        <i className={`icon fas fa-${present ? "check" : "times"}`} />
        <span className="EncryptionCard-badgeLabel">
          {trans(`status.${kind}_key_label`)}
        </span>
        <span className="EncryptionCard-badgeState">
          {trans(`status.${present ? "present" : "absent"}`)}
        </span>
      </div>
    );
  }

  copyPublicKey(publicKey: string) {
    if (!publicKey) return;
    if (!navigator.clipboard) {
      app.alerts.show(
        { type: "error" },
        extractText(app.translator.trans("ramon-verified.lib.errors.clipboard"))
      );
      return;
    }
    navigator.clipboard.writeText(publicKey).then(
      () => {
        this.publicCopied = true;
        m.redraw();
        setTimeout(() => {
          this.publicCopied = false;
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

  async generate(): Promise<boolean> {
    const res = await this.encryption.generate(false);
    if (!res) return false;
    this.showRevealModal(res);
    return true;
  }

  openRegenerate() {
    app.modal.show(RegenerateConfirmModal, {
      onConfirm: async () => {
        const res = await this.encryption.generate(true);
        if (!res) return false;
        this.showRevealModal(res);
        return true;
      },
    });
  }

  showRevealModal(res: KeypairResult) {
    app.modal.show(KeypairRevealModal, {
      privateKey: res.privateKey,
      configKey: res.configKey,
      orphanedDocuments: res.orphanedDocuments || 0,
    });
  }
}
