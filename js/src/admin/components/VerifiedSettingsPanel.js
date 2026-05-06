import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Switch from 'flarum/common/components/Switch';
import UploadImageButton from 'flarum/common/components/UploadImageButton';
import extractText from 'flarum/common/utils/extractText';
import VerificationRequestsSection from './VerificationRequestsSection';
import getBadgeSvg, { getBadgeColor, resolveAssetUrl } from '../../common/utils/getBadgeSvg';

const trans = (key) => app.translator.trans(`ramon-verified.admin.${key}`);

const isOn = (raw) => raw === true || raw === 'true' || raw === 1 || raw === '1';
const getBool = (key) => isOn(app.data.settings[key]);
const getStr = (key) => String(app.data.settings[key] ?? '');

function saveSetting(payload) {
  const apiUrl = (app.forum.attribute('apiUrl') || '/api').replace(/\/+$/, '');
  return app.request({ method: 'POST', url: `${apiUrl}/settings`, body: payload });
}

// ─── Tiny helpers used by the panel ──────────────────────────────────────────

const SubDivider = {
  view() {
    return <div className="VerifiedAdmin-divider" />;
  },
};

// Self-contained boolean toggle — same pattern as avocado's AdminToggle.
// Mutates app.data.settings synchronously so the next view() pass sees the
// new value, then persists to /api/settings.
class AdminToggle extends Component {
  view() {
    const { settingKey, label, help } = this.attrs;
    const value = getBool(settingKey);

    return (
      <div className="Form-group VerifiedAdmin-toggle">
        <Switch
          state={value}
          onchange={(checked) => {
            app.data.settings[settingKey] = checked;
            m.redraw();
            saveSetting({ [settingKey]: checked ? '1' : '0' });
          }}
        >
          {label}
        </Switch>
        {help && <p className="helpText">{help}</p>}
      </div>
    );
  }
}

// Editable document-type list. Each row = `{ id, label }`. Persisted as a
// JSON string in the `ramon-verified.document_types` setting; the backend
// parses it back into a real array when serialising to the forum payload,
// so the forum-side modal can iterate it directly.
class DocumentTypesEditor extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.types = this.parse(getStr('ramon-verified.document_types'));
    this._timer = null;
  }

  parse(raw) {
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed)
        ? parsed
            .filter((r) => r && typeof r === 'object')
            .map((r) => ({ id: String(r.id || ''), label: String(r.label || '') }))
        : [];
    } catch (e) {
      return [];
    }
  }

  serialize() {
    // Filter out blank rows so we don't persist half-edited entries — but
    // keep them in the local state so the editor doesn't yank out a row the
    // admin is mid-typing on.
    return JSON.stringify(this.types.filter((r) => r.id.trim() && r.label.trim()));
  }

  flushNow() {
    const raw = this.serialize();
    app.data.settings['ramon-verified.document_types'] = raw;
    saveSetting({ 'ramon-verified.document_types': raw });
  }

  flushSoon() {
    clearTimeout(this._timer);
    this._timer = setTimeout(() => this.flushNow(), 400);
  }

  add() {
    this.types = this.types.concat([{ id: '', label: '' }]);
    m.redraw();
  }

  remove(idx) {
    this.types = this.types.filter((_, i) => i !== idx);
    m.redraw();
    this.flushNow();
  }

  update(idx, field, value) {
    this.types = this.types.map((r, i) => (i === idx ? { ...r, [field]: value } : r));
    this.flushSoon();
    m.redraw();
  }

  view() {
    return (
      <div className="VerifiedAdmin-row VerifiedAdmin-types">
        <div className="VerifiedAdmin-types-header">
          <span className="VerifiedAdmin-types-headerId">{trans('settings.document_type_id_header')}</span>
          <span className="VerifiedAdmin-types-headerLabel">{trans('settings.document_type_label_header')}</span>
        </div>

        <div className="VerifiedAdmin-types-list">
          {this.types.length === 0 ? (
            <p className="VerifiedAdmin-types-empty helpText">
              {trans('settings.document_types_empty')}
            </p>
          ) : (
            this.types.map((row, idx) => (
              <div className="VerifiedAdmin-types-row" key={idx}>
                <input
                  type="text"
                  className="FormControl VerifiedAdmin-types-input VerifiedAdmin-types-id"
                  value={row.id}
                  placeholder="rg"
                  spellcheck="false"
                  autocomplete="off"
                  oninput={(e) => this.update(idx, 'id', e.target.value)}
                />
                <input
                  type="text"
                  className="FormControl VerifiedAdmin-types-input VerifiedAdmin-types-label"
                  value={row.label}
                  placeholder={extractText(trans('settings.document_type_label_placeholder'))}
                  oninput={(e) => this.update(idx, 'label', e.target.value)}
                />
                <button
                  type="button"
                  className="VerifiedAdmin-types-remove"
                  onclick={() => this.remove(idx)}
                  aria-label={extractText(trans('settings.document_type_remove'))}
                  title={extractText(trans('settings.document_type_remove'))}
                >
                  <i className="icon fas fa-times" />
                </button>
              </div>
            ))
          )}
        </div>

        <button
          type="button"
          className="VerifiedAdmin-types-add"
          onclick={() => this.add()}
        >
          <i className="icon fas fa-plus" />
          {trans('settings.document_type_add')}
        </button>
      </div>
    );
  }
}

// ─── The panel ───────────────────────────────────────────────────────────────

/**
 * Single-column, card-based admin panel for the Verified extension.
 * Replaces the default extension settings grid.
 */
export default class VerifiedSettingsPanel extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this._colorTimer = null;
    this._sizeTimer = null;
  }

  view() {
    // Document types only matter when documents are actually required —
    // hide the whole section otherwise to keep the panel uncluttered.
    const requireDoc = getBool('ramon-verified.require_document');

    return (
      <div className="VerifiedAdmin">
        {this.previewCard()}
        {this.appearanceCard()}
        {this.behaviourCard()}
        {requireDoc ? this.documentTypesCard() : null}
        {this.requestsCard()}
      </div>
    );
  }

  // ---- Preview card ------------------------------------------------------

  previewCard() {
    const color = getBadgeColor();
    const sizeRaw = parseFloat(getStr('ramon-verified.badge_size'));
    const size = Number.isFinite(sizeRaw) && sizeRaw > 0 ? sizeRaw : 1.2;
    const showTooltip = getBool('ramon-verified.show_tooltip');

    // Match the real-world rendering exactly. The post-header username uses
    // 14px bold (`.PostUser-name a` in core's Post.less), so the badge here
    // inherits the same em-scale.
    const lineStyle = { fontSize: '14px' };

    const badgeStyle = {
      width: size + 'em',
      height: size + 'em',
    };
    if (color) badgeStyle.color = color;

    const tooltipText = extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    // When the rich popover is enabled, render the full anchor + popover
    // structure so admins can hover the preview badge and see exactly
    // what their users will see. When disabled, render a plain badge with
    // the native browser `title` tooltip — same fallback the forum-side
    // VerifiedBadge component uses.
    const badgeNode = showTooltip
      ? (
        <span className="VerifiedPopover-anchor">
          <span
            className="VerifiedBadge VerifiedBadge--inAnchor"
            style={badgeStyle}
            role="img"
            aria-label={tooltipText}
            tabIndex="0"
          >
            {m.trust(getBadgeSvg())}
          </span>
          {this.previewPopover(color)}
        </span>
      )
      : (
        <span
          className="VerifiedBadge"
          style={badgeStyle}
          role="img"
          aria-label={tooltipText}
          title={tooltipText}
        >
          {m.trust(getBadgeSvg())}
        </span>
      );

    return (
      <div className="VerifiedAdmin-card VerifiedAdmin-preview">
        <div className="VerifiedAdmin-preview-line" style={lineStyle}>
          <span className="VerifiedAdmin-preview-name">
            {app.session.user ? app.session.user.displayName() : 'User'}
          </span>
          {badgeNode}
          <span className="VerifiedAdmin-preview-meta">{trans('preview.meta_sample')}</span>
        </div>
        <div className="helpText">{trans('preview.helper')}</div>
      </div>
    );
  }

  /**
   * Mini popover panel for the admin preview. Mirrors the structure of
   * VerifiedPopover so the same CSS shows it on hover, but uses static
   * sample data (admin's own user info) so we don't depend on the forum
   * `app.forum` attributes that aren't all available in admin context.
   */
  previewPopover(color) {
    const u = app.session.user;
    const username = u ? u.username() : 'user';
    const displayName = u ? u.displayName() : 'User';
    const avatarUrl = u && u.avatarUrl && u.avatarUrl();

    return (
      <span className="VerifiedPopover" role="tooltip">
        <span className="VerifiedPopover-arrow" aria-hidden="true" />

        <span className="VerifiedPopover-header">
          <span className="VerifiedPopover-headerIcon" style={color ? { color } : null}>
            {m.trust(getBadgeSvg())}
          </span>
          <span className="VerifiedPopover-headerText">
            {app.translator.trans('ramon-verified.lib.popover.headline')}
          </span>
        </span>

        <span className="VerifiedPopover-body">
          <span className="VerifiedPopover-user">
            <span className="VerifiedPopover-avatar">
              {avatarUrl
                ? <img className="Avatar" src={avatarUrl} alt={displayName} />
                : <span className="Avatar" aria-hidden="true">{(username[0] || 'U').toUpperCase()}</span>}
            </span>
            <span className="VerifiedPopover-userText">
              <span className="VerifiedPopover-username">{username}</span>
              <span className="VerifiedPopover-displayName">{displayName}</span>
            </span>
          </span>
        </span>
      </span>
    );
  }

  // ---- Appearance card ---------------------------------------------------

  appearanceCard() {
    const path = getStr('ramon-verified.badge_svg_path');
    const customColorEnabled = getBool('ramon-verified.custom_color_enabled');

    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('settings.section_appearance')}</h3>
          <p className="helpText">{trans('settings.section_appearance_help')}</p>
        </header>

        {this.sizeSliderRow()}

        <SubDivider />

        <AdminToggle
          settingKey="ramon-verified.custom_color_enabled"
          label={trans('settings.custom_color_label')}
          help={trans('settings.custom_color_help')}
        />

        {customColorEnabled && (
          <>
            <SubDivider />
            {this.colorPickerRow()}
          </>
        )}

        <SubDivider />

        <div className="VerifiedAdmin-row">
          <label className="VerifiedAdmin-label">{trans('settings.custom_svg_label')}</label>
          <UploadImageButton
            name="verified-badge"
            routePath="verified/badge-svg"
            value={path}
            url={resolveAssetUrl(path)}
          />
          <p className="helpText">{trans('settings.custom_svg_help')}</p>
        </div>
      </section>
    );
  }

  sizeSliderRow() {
    const raw = parseFloat(getStr('ramon-verified.badge_size'));
    const value = Number.isFinite(raw) && raw > 0 ? raw : 1.2;
    const clamped = Math.max(0.6, Math.min(value, 3));

    return (
      <div className="VerifiedAdmin-row">
        <label className="VerifiedAdmin-label">
          {trans('settings.badge_size_label')}
          <span className="VerifiedAdmin-sizeValue">{clamped.toFixed(2)}×</span>
        </label>
        <input
          type="range"
          className="VerifiedAdmin-rangeInput"
          min="0.6"
          max="3"
          step="0.05"
          value={clamped}
          oninput={(e) => this.queueSize(e.target.value)}
        />
        <p className="helpText">{trans('settings.badge_size_help')}</p>
      </div>
    );
  }

  colorPickerRow() {
    const colorValue = getStr('ramon-verified.badge_color');

    return (
      <div className="VerifiedAdmin-row VerifiedAdmin-subGroup">
        <label className="VerifiedAdmin-label">{trans('settings.badge_color_label')}</label>
        <div className="VerifiedAdmin-colorRow">
          <input
            type="color"
            className="VerifiedAdmin-colorPicker"
            value={/^#[0-9a-f]{6}$/i.test(colorValue) ? colorValue : (app.forum.attribute('themePrimaryColor') || '#1d9bf0')}
            oninput={(e) => this.queueColor(e.target.value)}
          />
          <input
            type="text"
            className="FormControl VerifiedAdmin-colorInput"
            value={colorValue}
            placeholder={app.forum.attribute('themePrimaryColor') || '#1d9bf0'}
            oninput={(e) => this.queueColor(e.target.value)}
          />
          <button
            type="button"
            className="Button Button--text VerifiedAdmin-clearBtn"
            onclick={() => this.queueColor('', true)}
            title={trans('settings.badge_color_reset')}
          >
            <i className="icon fas fa-rotate-left" /> {trans('settings.badge_color_reset')}
          </button>
        </div>
        <p className="helpText">{trans('settings.badge_color_help')}</p>
      </div>
    );
  }

  // ---- Behaviour card ----------------------------------------------------

  behaviourCard() {
    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('settings.section_behaviour')}</h3>
          <p className="helpText">{trans('settings.section_behaviour_help')}</p>
        </header>

        <AdminToggle
          settingKey="ramon-verified.requests_open"
          label={trans('settings.requests_open_label')}
          help={trans('settings.requests_open_help')}
        />
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.show_tooltip"
          label={trans('settings.show_tooltip_label')}
          help={trans('settings.show_tooltip_help')}
        />
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.require_document"
          label={trans('settings.require_document_label')}
          help={trans('settings.require_document_help')}
        />
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.lock_avatar"
          label={trans('settings.lock_avatar_label')}
          help={trans('settings.lock_avatar_help')}
        />
      </section>
    );
  }

  // ---- Document types card ----------------------------------------------

  documentTypesCard() {
    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('settings.section_document_types')}</h3>
          <p className="helpText">{trans('settings.section_document_types_help')}</p>
        </header>

        <DocumentTypesEditor />
      </section>
    );
  }

  // ---- Requests card -----------------------------------------------------

  requestsCard() {
    return (
      <section className="VerifiedAdmin-card VerifiedAdmin-card--requests">
        <VerificationRequestsSection />
      </section>
    );
  }

  // ---- Helpers -----------------------------------------------------------

  queueColor(value, immediate = false) {
    const trimmed = (value || '').trim();
    app.data.settings['ramon-verified.badge_color'] = trimmed;

    clearTimeout(this._colorTimer);
    const flush = () => saveSetting({ 'ramon-verified.badge_color': trimmed });
    if (immediate) flush();
    else this._colorTimer = setTimeout(flush, 500);

    m.redraw();
  }

  queueSize(value) {
    const num = parseFloat(value);
    if (!Number.isFinite(num)) return;
    const clamped = Math.max(0.6, Math.min(num, 3)).toFixed(2);

    app.data.settings['ramon-verified.badge_size'] = clamped;
    // Override the global custom property too so the preview badge resizes
    // immediately without waiting for the next page load.
    if (typeof document !== 'undefined') {
      document.documentElement.style.setProperty('--verified-size', clamped + 'em');
    }

    clearTimeout(this._sizeTimer);
    this._sizeTimer = setTimeout(
      () => saveSetting({ 'ramon-verified.badge_size': clamped }),
      400
    );

    m.redraw();
  }
}
