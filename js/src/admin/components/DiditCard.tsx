import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import { getBool, getStr, saveSetting, settings } from '../utils/settings';
import AdminToggle from './AdminToggle';

const trans = (key: string, params?: Record<string, unknown>) => app.translator.trans(`ramon-verified.admin.didit.${key}`, params ?? {});

const KEYS = {
  enabled: 'ramon-verified.didit_enabled',
  apiKey: 'ramon-verified.didit_api_key',
  secret: 'ramon-verified.didit_webhook_secret',
  workflow: 'ramon-verified.didit_workflow_id',
};

interface Workflow {
  id: string;
  label: string;
  type: string;
  isDefault: boolean;
}

type Check = { state: 'idle' | 'loading' | 'ok' | 'error'; status?: number };

export default class DiditCard extends Component<ComponentAttrs> {
  protected workflows: Workflow[] = [];
  protected check: Check = { state: 'idle' };
  protected timers: Record<string, ReturnType<typeof setTimeout>> = {};
  protected copied = false;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    if (getStr(KEYS.apiKey)) this.testConnection();
  }

  view() {
    const enabled = getBool(KEYS.enabled);

    return (
      <section className="VerifiedAdmin-card DiditCard">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('section_title')}</h3>
          <p className="helpText">{trans('section_help')}</p>
        </header>

        <AdminToggle settingKey={KEYS.enabled} label={trans('toggle_label')} help={trans('toggle_help')} />

        {enabled ? (
          <>
            <div className="VerifiedAdmin-divider" />
            {this.secretField(KEYS.apiKey, 'api_key')}
            {this.connectionRow()}
            {this.workflowField()}
            <div className="VerifiedAdmin-divider" />
            {this.webhookUrl()}
            {this.secretField(KEYS.secret, 'webhook_secret')}
            {!getStr(KEYS.secret) ? <div className="Alert Alert--warning DiditCard-msg">{trans('webhook_missing')}</div> : null}
          </>
        ) : null}
      </section>
    );
  }

  // Salva com atraso enquanto se digita; a chave nunca vai para o fórum.
  secretField(key: string, name: string): Mithril.Children {
    return (
      <div className="Form-group VerifiedAdmin-row">
        <label className="VerifiedAdmin-label" for={`DiditCard-${name}`}>
          {trans(`${name}_label`)}
        </label>
        <input
          id={`DiditCard-${name}`}
          type="password"
          className="FormControl"
          autocomplete="off"
          spellcheck="false"
          value={getStr(key)}
          oninput={(e: Event) => this.queue(key, (e.target as HTMLInputElement).value, key === KEYS.apiKey)}
        />
        <p className="helpText">{trans(`${name}_help`)}</p>
      </div>
    );
  }

  connectionRow(): Mithril.Children {
    if (!getStr(KEYS.apiKey)) return null;

    const { state, status } = this.check;

    return (
      <div className="DiditCard-connection">
        {state === 'ok' ? (
          <span className="DiditCard-status DiditCard-status--ok">
            <i className="icon fas fa-check" /> {trans('connection_ok', { count: this.workflows.length })}
          </span>
        ) : state === 'error' ? (
          <span className="DiditCard-status DiditCard-status--error">
            <i className="icon fas fa-times" /> {status === 401 || status === 403 ? trans('connection_invalid_key') : trans('connection_error')}
          </span>
        ) : null}
        <Button className="Button" icon="fas fa-rotate" loading={state === 'loading'} onclick={() => this.testConnection()}>
          {trans('test_button')}
        </Button>
      </div>
    );
  }

  workflowField(): Mithril.Children {
    if (this.check.state !== 'ok') return null;

    return (
      <div className="Form-group VerifiedAdmin-row">
        <label className="VerifiedAdmin-label" for="DiditCard-workflow">
          {trans('workflow_label')}
        </label>
        <select
          id="DiditCard-workflow"
          className="FormControl"
          value={getStr(KEYS.workflow)}
          onchange={(e: Event) => this.save(KEYS.workflow, (e.target as HTMLSelectElement).value)}
        >
          <option value="">{extractText(trans('workflow_default'))}</option>
          {this.workflows.map((w) => (
            <option value={w.id}>
              {w.label}
              {w.isDefault ? ' ★' : ''}
            </option>
          ))}
        </select>
        <p className="helpText">{trans('workflow_help')}</p>
      </div>
    );
  }

  webhookUrl(): Mithril.Children {
    const url = `${app.forum.attribute<string>('apiUrl')}/verified/didit/webhook`;

    return (
      <div className="Form-group VerifiedAdmin-row">
        <label className="VerifiedAdmin-label">{trans('webhook_url_label')}</label>
        <div className="DiditCard-copyRow">
          <code className="DiditCard-url">{url}</code>
          <Button className="Button Button--icon" icon="fas fa-copy" aria-label={extractText(trans('copy'))} onclick={() => this.copy(url)}>
            {this.copied ? extractText(trans('copied')) : ''}
          </Button>
        </div>
        <p className="helpText">{trans('webhook_url_help')}</p>
      </div>
    );
  }

  queue(key: string, value: string, retest: boolean): void {
    settings()[key] = value;
    clearTimeout(this.timers[key]);
    this.timers[key] = setTimeout(() => {
      this.save(key, value).then(() => retest && this.testConnection());
    }, 600);
  }

  save(key: string, value: string): Promise<unknown> {
    const previous = settings()[key];
    settings()[key] = value;
    m.redraw();

    return saveSetting({ [key]: value }).catch(() => {
      settings()[key] = previous;
      m.redraw();
    });
  }

  testConnection(): void {
    this.check = { state: 'loading' };
    m.redraw();

    app
      .request<{ ok: boolean; status?: number; workflows: Workflow[] }>({
        method: 'GET',
        url: `${app.forum.attribute<string>('apiUrl')}/verified/didit/workflows`,
      })
      .then((res) => {
        this.workflows = res.workflows || [];
        this.check = res.ok ? { state: 'ok' } : { state: 'error', status: res.status };
      })
      .catch(() => {
        this.check = { state: 'error' };
      })
      .finally(() => m.redraw());
  }

  copy(text: string): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied = true;
      m.redraw();
      setTimeout(() => {
        this.copied = false;
        m.redraw();
      }, 2000);
    });
  }
}
