import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.encryption.${key}`, params ?? {});

const apiUrl = () => (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');

const CONFIG_KEY = 'verified-private-key';

interface EncryptionStatus {
  available: boolean;
  has_public_key: boolean;
  private_key_present: boolean;
  keys_match: boolean | null;
  healthy: boolean;
  requires_regeneration: boolean;
  public_key: string | null;
}

interface KeypairRevealAttrs extends IInternalModalAttrs {
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
class KeypairRevealModal extends Modal<KeypairRevealAttrs> {
  protected copied = false;

  className() {
    return 'EncryptionRevealModal Modal--medium';
  }

  title() {
    return trans('reveal_modal.title');
  }

  content() {
    const { privateKey, configKey, orphanedDocuments = 0 } = this.attrs;
    const snippet = `'${configKey}' => '${privateKey}',`;

    return (
      <div className="Modal-body">
        <p>{trans('reveal_modal.intro')}</p>

        {orphanedDocuments > 0 && (
          <div className="Alert Alert--warning EncryptionReveal-orphaned">
            {trans('reveal_modal.orphaned', { count: orphanedDocuments })}
          </div>
        )}

        <div className="EncryptionReveal-warning Alert Alert--error">
          <strong>{trans('reveal_modal.warning_title')}</strong>
          <p>{trans('reveal_modal.warning_body')}</p>
        </div>

        <label className="EncryptionReveal-label">{trans('reveal_modal.snippet_label')}</label>
        <pre className="EncryptionReveal-snippet"><code>{snippet}</code></pre>

        <div className="Form-group EncryptionReveal-actions">
          <Button className="Button" icon="fas fa-copy" onclick={() => this.copy(snippet)}>
            {this.copied ? trans('reveal_modal.copied') : trans('reveal_modal.copy_button')}
          </Button>
          <Button className="Button Button--primary" onclick={() => this.hide()}>
            {trans('reveal_modal.close')}
          </Button>
        </div>
      </div>
    );
  }

  copy(snippet: string) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(snippet).then(() => {
      this.copied = true;
      m.redraw();
      setTimeout(() => {
        this.copied = false;
        m.redraw();
      }, 2000);
    });
  }
}

interface RegenerateConfirmAttrs extends IInternalModalAttrs {
  onConfirm: () => Promise<unknown>;
}

class RegenerateConfirmModal extends Modal<RegenerateConfirmAttrs> {
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
      await this.attrs.onConfirm();
    } catch (e) {
      // The parent surfaces any error via app.alerts.
    }
    this.submitting = false;
    this.hide();
  }
}

export default class EncryptionCard extends Component<ComponentAttrs> {
  protected status: EncryptionStatus | null = null;
  protected loading = true;
  protected publicCopied = false;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.refresh();
  }

  view() {
    return (
      <section className="VerifiedAdmin-card EncryptionCard">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('section_title')}</h3>
          <p className="helpText">{trans('section_help')}</p>
        </header>

        {this.loading ? <LoadingIndicator /> : this.body()}
      </section>
    );
  }

  body() {
    if (!this.status) {
      return <p className="helpText">{trans('status.unknown')}</p>;
    }

    const s = this.status;

    if (!s.available) {
      return <div className="Alert Alert--error">{trans('status.libsodium_missing')}</div>;
    }

    return (
      <>
        <div className="EncryptionCard-status">
          {this.statusBadge('public', s.has_public_key)}
          {this.statusBadge('private', s.private_key_present)}
        </div>

        {s.healthy && (
          <div className="Alert Alert--success EncryptionCard-msg">{trans('status.healthy')}</div>
        )}

        {!s.has_public_key && !s.private_key_present && (
          <div className="EncryptionCard-msg">
            <p className="helpText">{trans('status.not_setup')}</p>
            <Button className="Button Button--primary" icon="fas fa-key" onclick={() => this.generate()}>
              {trans('actions.generate')}
            </Button>
          </div>
        )}

        {/* Keys mismatch — both halves are present but they are not from
            the same pair. Existing encrypted documents are unreadable. */}
        {s.has_public_key && s.private_key_present && s.keys_match === false && (
          <div className="Alert Alert--error EncryptionCard-msg">
            <strong>{trans('status.mismatch_title')}</strong>
            <p>{trans('status.mismatch_body')}</p>
            <p>
              <code>'{CONFIG_KEY}'</code>
            </p>
          </div>
        )}

        {/* Private key absent. */}
        {s.has_public_key && !s.private_key_present && (
          <div className="Alert Alert--error EncryptionCard-msg">
            <strong>{trans('status.private_missing_title')}</strong>
            <p>{trans('status.private_missing_body')}</p>
            <p>
              <code>'{CONFIG_KEY}'</code>
            </p>
          </div>
        )}

        {s.has_public_key && this.publicKeyPanel(s.public_key || '', s.healthy)}
      </>
    );
  }

  publicKeyPanel(publicKey: string, healthy: boolean) {
    return (
      <div className="EncryptionCard-publicKey">
        <label className="EncryptionCard-publicKeyLabel">{trans('public_key.label')}</label>
        <div className="EncryptionCard-publicKeyRow">
          <pre className="EncryptionCard-publicKeyValue"><code>{publicKey}</code></pre>
          <Button
            className="Button Button--icon"
            icon="fas fa-copy"
            title={extractText(trans('public_key.copy_title'))}
            aria-label={extractText(trans('public_key.copy_title'))}
            onclick={() => this.copyPublicKey(publicKey)}
          >
            {this.publicCopied ? extractText(trans('public_key.copied')) : ''}
          </Button>
        </div>

        <p className="helpText">
          {healthy ? trans('public_key.help_healthy') : trans('public_key.help_broken')}
        </p>

        <Button
          className="Button Button--danger EncryptionCard-rotateBtn"
          icon="fas fa-rotate"
          onclick={() => this.openRegenerate()}
        >
          {trans('public_key.remove_button')}
        </Button>
      </div>
    );
  }

  statusBadge(kind: 'public' | 'private', present: boolean) {
    return (
      <div className={`EncryptionCard-badge EncryptionCard-badge--${present ? 'ok' : 'missing'}`}>
        <i className={`icon fas fa-${present ? 'check' : 'times'}`} />
        <span className="EncryptionCard-badgeLabel">{trans(`status.${kind}_key_label`)}</span>
        <span className="EncryptionCard-badgeState">
          {trans(`status.${present ? 'present' : 'absent'}`)}
        </span>
      </div>
    );
  }

  copyPublicKey(publicKey: string) {
    if (!publicKey || !navigator.clipboard) return;
    navigator.clipboard.writeText(publicKey).then(() => {
      this.publicCopied = true;
      m.redraw();
      setTimeout(() => {
        this.publicCopied = false;
        m.redraw();
      }, 2000);
    });
  }

  refresh(): Promise<void> {
    this.loading = true;
    return app.request<EncryptionStatus>({
      method: 'GET',
      url: `${apiUrl()}/verified/encryption/status`,
    })
      .then((res) => {
        this.status = res;
      })
      .catch(() => {
        this.status = null;
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  // Same shape as Flarum core's CreateUserModal flow: do the API work,
  // update local state, then show the result modal. The parent's status
  // panel is already refreshed by the time the user closes the modal —
  // no callback / lifecycle plumbing needed.
  async generate() {
    const res = await app.request<{
      privateKey: string;
      configKey: string;
      orphanedDocuments?: number;
    }>({
      method: 'POST',
      url: `${apiUrl()}/verified/encryption/generate-keypair`,
      body: {},
    });
    await this.refresh();
    app.modal.show(KeypairRevealModal, {
      privateKey: res.privateKey,
      configKey: res.configKey,
      orphanedDocuments: res.orphanedDocuments || 0,
    });
  }

  openRegenerate() {
    app.modal.show(RegenerateConfirmModal, {
      onConfirm: async () => {
        const res = await app.request<{
          privateKey: string;
          configKey: string;
          orphanedDocuments?: number;
        }>({
          method: 'POST',
          url: `${apiUrl()}/verified/encryption/generate-keypair`,
          body: { acknowledgeLoss: true },
        });
        await this.refresh();
        app.modal.show(KeypairRevealModal, {
          privateKey: res.privateKey,
          configKey: res.configKey,
          orphanedDocuments: res.orphanedDocuments || 0,
        });
      },
    });
  }
}
