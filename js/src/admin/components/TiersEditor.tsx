import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Group from 'flarum/common/models/Group';
import GroupBadge from 'flarum/common/components/GroupBadge';
import sortGroups from 'flarum/common/utils/sortGroups';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import getBadgeSvg, { getBadgeSize } from '../../common/utils/getBadgeSvg';
import { sanitiseDescription } from '../../common/utils/tiers';

const trans = (key: string) => app.translator.trans(`ramon-verified.admin.${key}`);

const SETTING_KEY = 'ramon-verified.tiers';

interface TierRow {
  id: string;
  label: string;
  color: string;
  description: string;
  learnMoreUrl: string;
  autoGroups: number[];
}

const settings = (): Record<string, unknown> =>
  ((app as unknown as { data: { settings: Record<string, unknown> } }).data.settings) || {};

function saveSetting(payload: Record<string, unknown>): Promise<unknown> {
  const apiUrl = (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');
  return app.request({ method: 'POST', url: `${apiUrl}/settings`, body: payload });
}

function emptyRow(): TierRow {
  return { id: '', label: '', color: '#1d9bf0', description: '', learnMoreUrl: '', autoGroups: [] };
}

/**
 * Editor for the multi-tier badge config.
 *
 * UI shape: a vertical list of compact tier cards. Click a card's header to
 * expand/collapse the full editor inline. This keeps the page short when you
 * have several tiers and lets the eye scan label/color/id quickly.
 *
 * Auto-grant groups use chip-style toggles (not Flarum's PermissionDropdown)
 * because Flarum's standard control hides admin from the togglable list.
 */
export default class TiersEditor extends Component<ComponentAttrs> {
  protected rows: TierRow[] = [];
  protected expandedIdx: number | null = null;
  private _flushTimer: ReturnType<typeof setTimeout> | null = null;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.rows = this.parse(String(settings()[SETTING_KEY] ?? ''));
    this._flushTimer = null;
    this.expandedIdx = null;
  }

  parse(raw: string): TierRow[] {
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed.map((r: unknown) => {
        const row = (r && typeof r === 'object' ? r : {}) as Record<string, unknown>;
        return {
          id: String(row.id || '').trim(),
          label: String(row.label || '').trim(),
          color: String(row.color || '').trim(),
          description: String(row.description || '').trim(),
          learnMoreUrl: String(row.learnMoreUrl || '').trim(),
          autoGroups: Array.isArray(row.autoGroups)
            ? row.autoGroups.map((g) => parseInt(String(g), 10)).filter((n) => Number.isFinite(n) && n > 0)
            : [],
        };
      });
    } catch (e) {
      return [];
    }
  }

  serialize(): string {
    return JSON.stringify(
      this.rows
        .filter((r) => r.id.trim() && r.label.trim())
        .map((r) => ({
          id: r.id.trim().toLowerCase(),
          label: r.label.trim(),
          color: r.color.trim(),
          description: r.description.trim(),
          learnMoreUrl: this.normaliseUrl(r.learnMoreUrl),
          autoGroups: r.autoGroups,
        }))
    );
  }

  /**
   * Trim and auto-prepend `https://` when the admin types a URL without a
   * protocol. The backend `TierConfig` regex only accepts http/https URLs,
   * so silently dropping bare hostnames was a sharp edge — admins typed
   * `policy.example.com` and the link disappeared on save with no feedback.
   */
  normaliseUrl(raw: string): string {
    const trimmed = (raw || '').trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    // If it already has another scheme (mailto:, ftp:, …) leave it alone —
    // it'll be rejected server-side, but we shouldn't silently rewrite it.
    if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) return trimmed;
    return 'https://' + trimmed;
  }

  flushNow(): void {
    const raw = this.serialize();
    settings()[SETTING_KEY] = raw;
    saveSetting({ [SETTING_KEY]: raw });
  }

  flushSoon(): void {
    if (this._flushTimer) clearTimeout(this._flushTimer);
    this._flushTimer = setTimeout(() => this.flushNow(), 400);
  }

  add(): void {
    this.rows = [...this.rows, emptyRow()];
    this.expandedIdx = this.rows.length - 1;
    m.redraw();
  }

  remove(idx: number): void {
    if (!window.confirm(extractText(trans('settings.tiers.remove_confirm')))) return;
    this.rows = this.rows.filter((_, i) => i !== idx);
    if (this.expandedIdx === idx) this.expandedIdx = null;
    else if (this.expandedIdx !== null && this.expandedIdx > idx) this.expandedIdx -= 1;
    this.flushNow();
    m.redraw();
  }

  update(idx: number, patch: Partial<TierRow>): void {
    this.rows = this.rows.map((r, i) => (i === idx ? { ...r, ...patch } : r));
    this.flushSoon();
    m.redraw();
  }

  toggleGroup(idx: number, groupId: number): void {
    const row = this.rows[idx];
    if (!row) return;
    const isOn = row.autoGroups.indexOf(groupId) !== -1;
    const next = isOn
      ? row.autoGroups.filter((g) => g !== groupId)
      : [...row.autoGroups, groupId];
    this.update(idx, { autoGroups: next });
  }

  toggleExpand(idx: number): void {
    this.expandedIdx = this.expandedIdx === idx ? null : idx;
    m.redraw();
  }

  /**
   * Toolbar action: wraps the textarea's current selection in <strong> or
   * <em>. If nothing is selected, inserts the empty tag pair at the caret so
   * the admin can type inside.
   *
   * Operates on the live DOM value first, then mirrors the result back into
   * the row state so Mithril doesn't fight us on the redraw. We restore the
   * caret position right after the inserted markup so typing keeps flowing.
   */
  wrapSelection(e: Event, idx: number, tag: 'strong' | 'em'): void {
    e.preventDefault();

    const button = e.currentTarget as HTMLElement;
    const wrapper = button.closest('.VerifiedTier-field');
    const ta = wrapper && wrapper.querySelector('textarea.VerifiedTier-descArea') as HTMLTextAreaElement | null;
    if (!ta) return;

    const start = ta.selectionStart ?? 0;
    const end = ta.selectionEnd ?? 0;
    const value = ta.value;
    const open = `<${tag}>`;
    const close = `</${tag}>`;

    const next = value.slice(0, start) + open + value.slice(start, end) + close + value.slice(end);

    ta.value = next;
    ta.focus();
    const caret = end === start
      ? start + open.length
      : end + open.length + close.length;
    ta.setSelectionRange(caret, caret);

    this.update(idx, { description: next });
  }

  view(): Mithril.Children {
    if (this.rows.length === 0) {
      return this.emptyState();
    }

    const groups = sortGroups(
      app.store.all<Group>('groups').filter((g) => g.id() !== Group.GUEST_ID)
    );

    return (
      <div className="VerifiedTiers">
        <ol className="VerifiedTiers-list">
          {this.rows.map((row, idx) => this.renderCard(row, idx, groups))}
        </ol>

        <Button
          className="Button VerifiedTiers-add"
          icon="fas fa-plus"
          onclick={() => this.add()}
        >
          {trans('settings.tiers.add')}
        </Button>
      </div>
    );
  }

  emptyState(): Mithril.Children {
    return (
      <div className="VerifiedTiers VerifiedTiers--empty">
        <div className="VerifiedTiers-emptyIcon" aria-hidden="true">
          <i className="icon fas fa-certificate" />
        </div>
        <h3 className="VerifiedTiers-emptyTitle">{trans('settings.tiers.empty_title')}</h3>
        <p className="VerifiedTiers-emptyText">{trans('settings.tiers.empty')}</p>
        <Button
          className="Button Button--primary VerifiedTiers-add"
          icon="fas fa-plus"
          onclick={() => this.add()}
        >
          {trans('settings.tiers.add_first')}
        </Button>
      </div>
    );
  }

  renderCard(row: TierRow, idx: number, groups: Group[]): Mithril.Children {
    const isExpanded = this.expandedIdx === idx;
    const swatchColor = /^#[0-9a-f]{3,8}$/i.test(row.color) ? row.color : 'var(--primary-color)';
    const groupCount = row.autoGroups.length;

    return (
      <li
        className={'VerifiedTier' + (isExpanded ? ' VerifiedTier--expanded' : '')}
        style={{ '--tier-color': swatchColor } as Record<string, string>}
        key={idx}
      >
        <button
          type="button"
          className="VerifiedTier-header"
          aria-expanded={isExpanded}
          onclick={() => this.toggleExpand(idx)}
        >
          <span className="VerifiedTier-swatch" aria-hidden="true">
            <i className="icon fas fa-certificate" />
          </span>

          <span className="VerifiedTier-titleBlock">
            <span className="VerifiedTier-label">
              {row.label || trans('settings.tiers.unnamed')}
            </span>
            <span className="VerifiedTier-meta">
              <code className="VerifiedTier-id">{row.id || trans('settings.tiers.no_id')}</code>
              {groupCount > 0 && (
                <span className="VerifiedTier-groupsCount">
                  <i className="icon fas fa-users" />
                  {groupCount}
                </span>
              )}
              {row.learnMoreUrl && (
                <span className="VerifiedTier-linkChip" title={row.learnMoreUrl}>
                  <i className="icon fas fa-link" />
                </span>
              )}
            </span>
          </span>

          <span className="VerifiedTier-chevron" aria-hidden="true">
            <i className={'icon fas ' + (isExpanded ? 'fa-chevron-up' : 'fa-chevron-down')} />
          </span>
        </button>

        {isExpanded && this.renderEditForm(row, idx, groups)}
      </li>
    );
  }

  renderEditForm(row: TierRow, idx: number, groups: Group[]): Mithril.Children {
    const idValid = /^[a-z0-9_-]{1,32}$/.test(row.id.trim().toLowerCase());
    const urlValid = !!row.learnMoreUrl && /^https?:\/\//i.test(row.learnMoreUrl);
    const charCount = (row.description || '').length;
    const charCountClass = charCount >= 280
      ? 'VerifiedTier-charCount VerifiedTier-charCount--max'
      : charCount >= 240
        ? 'VerifiedTier-charCount VerifiedTier-charCount--warn'
        : 'VerifiedTier-charCount';

    return (
      <div className="VerifiedTier-body">
        {this.renderPreviewBanner(row)}

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">{trans('settings.tiers.section_identity')}</h4>
          <div className="VerifiedTier-grid">
            <div className="VerifiedTier-field">
              <label className="VerifiedTier-fieldLabel">{trans('settings.tiers.id_label')}</label>
              <input
                type="text"
                className={'FormControl' + (row.id && !idValid ? ' is-invalid' : '')}
                value={row.id}
                placeholder="blue"
                spellcheck="false"
                autocomplete="off"
                oninput={(e: Event) => this.update(idx, { id: (e.target as HTMLInputElement).value })}
              />
              <p className="VerifiedTier-fieldHelp">{trans('settings.tiers.id_help')}</p>
            </div>

            <div className="VerifiedTier-field">
              <label className="VerifiedTier-fieldLabel">{trans('settings.tiers.label_label')}</label>
              <input
                type="text"
                className="FormControl"
                value={row.label}
                placeholder={extractText(trans('settings.tiers.label_placeholder'))}
                oninput={(e: Event) => this.update(idx, { label: (e.target as HTMLInputElement).value })}
              />
            </div>
          </div>
        </div>

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">{trans('settings.tiers.section_appearance')}</h4>
          <div className="VerifiedTier-field">
            <label className="VerifiedTier-fieldLabel">{trans('settings.tiers.color_label')}</label>
            <div className="VerifiedTier-colorRow">
              <input
                type="color"
                className="VerifiedTier-colorPicker"
                value={/^#[0-9a-f]{6}$/i.test(row.color) ? row.color : '#1d9bf0'}
                oninput={(e: Event) => this.update(idx, { color: (e.target as HTMLInputElement).value })}
              />
              <input
                type="text"
                className="FormControl VerifiedTier-colorHex"
                value={row.color}
                placeholder="#1d9bf0"
                spellcheck="false"
                autocomplete="off"
                oninput={(e: Event) => this.update(idx, { color: (e.target as HTMLInputElement).value })}
              />
            </div>
          </div>
        </div>

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">{trans('settings.tiers.section_popover')}</h4>

          <div className="VerifiedTier-field">
            <div className="VerifiedTier-fieldHeader">
              <label className="VerifiedTier-fieldLabel">{trans('settings.tiers.description_label')}</label>
              <span className={charCountClass}>{charCount}/280</span>
            </div>
            <div className="VerifiedTier-descToolbar">
              <button
                type="button"
                className="VerifiedTier-descBtn"
                title={extractText(trans('settings.tiers.description_bold_title'))}
                aria-label={extractText(trans('settings.tiers.description_bold_title'))}
                onclick={(e: Event) => this.wrapSelection(e, idx, 'strong')}
              >
                <strong>B</strong>
              </button>
              <button
                type="button"
                className="VerifiedTier-descBtn"
                title={extractText(trans('settings.tiers.description_italic_title'))}
                aria-label={extractText(trans('settings.tiers.description_italic_title'))}
                onclick={(e: Event) => this.wrapSelection(e, idx, 'em')}
              >
                <em>I</em>
              </button>
              <span className="VerifiedTier-descToolbarHint">
                {trans('settings.tiers.description_toolbar_hint')}
              </span>
            </div>
            <textarea
              className="FormControl VerifiedTier-descArea"
              data-tier-idx={idx}
              rows={2}
              maxlength={320}
              value={row.description}
              placeholder={extractText(trans('settings.tiers.description_placeholder'))}
              oninput={(e: Event) => this.update(idx, { description: (e.target as HTMLTextAreaElement).value })}
            />
            <p className="VerifiedTier-fieldHelp">{trans('settings.tiers.description_help')}</p>
          </div>

          <div className="VerifiedTier-field">
            <label className="VerifiedTier-fieldLabel">{trans('settings.tiers.learn_more_label')}</label>
            <div className="VerifiedTier-urlRow">
              <input
                type="url"
                className="FormControl"
                value={row.learnMoreUrl}
                placeholder="https://exemplo.com/politica-de-verificacao"
                oninput={(e: Event) => this.update(idx, { learnMoreUrl: (e.target as HTMLInputElement).value })}
              />
              {urlValid && (
                <a
                  className="VerifiedTier-urlOpen"
                  href={row.learnMoreUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={extractText(trans('settings.tiers.learn_more_open'))}
                  aria-label={extractText(trans('settings.tiers.learn_more_open'))}
                >
                  <i className="icon fas fa-arrow-up-right-from-square" />
                </a>
              )}
            </div>
            <p className="VerifiedTier-fieldHelp">{trans('settings.tiers.learn_more_help')}</p>
          </div>
        </div>

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">{trans('settings.tiers.section_assignment')}</h4>

          <div className="VerifiedTier-field">
            <p className="VerifiedTier-fieldHelp VerifiedTier-fieldHelp--lead">
              {trans('settings.tiers.auto_groups_help')}
            </p>
            <div className="VerifiedTier-chips">
              {groups.length === 0 ? (
                <p className="VerifiedTier-fieldHelp">{trans('settings.tiers.auto_groups_empty')}</p>
              ) : (
                groups.map((group) => {
                  const gid = parseInt(String(group.id()), 10);
                  const checked = row.autoGroups.indexOf(gid) !== -1;
                  return (
                    <button
                      type="button"
                      key={gid}
                      className={'VerifiedTier-chip' + (checked ? ' VerifiedTier-chip--on' : '')}
                      aria-pressed={checked}
                      onclick={() => this.toggleGroup(idx, gid)}
                    >
                      <GroupBadge group={group} label={null} />
                      <span>{group.namePlural()}</span>
                      {checked && <i className="icon fas fa-check VerifiedTier-chipCheck" />}
                    </button>
                  );
                })
              )}
            </div>
          </div>
        </div>

        <div className="VerifiedTier-footer">
          <button
            type="button"
            className="Button Button--text VerifiedTier-removeBtn"
            onclick={() => this.remove(idx)}
          >
            <i className="icon fas fa-trash" /> {trans('settings.tiers.remove')}
          </button>
          <button
            type="button"
            className="Button Button--primary"
            onclick={() => this.toggleExpand(idx)}
          >
            <i className="icon fas fa-check" /> {trans('settings.tiers.done')}
          </button>
        </div>
      </div>
    );
  }

  /**
   * Live preview banner rendered at the top of the expanded body. Renders
   * the SAME markup the forum-side `VerifiedPopover` uses (.VerifiedPopover-*
   * classes) but wired to the in-progress tier values instead of a real user
   * tier — so the admin can hover the badge and see exactly the popover the
   * tier will produce in production, with the real size, color, headline,
   * and Saiba mais link.
   */
  renderPreviewBanner(row: TierRow): Mithril.Children {
    const color = /^#[0-9a-f]{3,8}$/i.test(row.color) ? row.color : null;
    const size = getBadgeSize();
    const u = app.session.user;
    const username = u ? u.username() : 'username';
    const displayName = u ? u.displayName() : 'Username';
    const avatarUrl = u && u.avatarUrl && u.avatarUrl();
    const ariaLabel = row.label || extractText(trans('settings.tiers.unnamed'));

    const badgeStyle: Record<string, string> = { '--verified-size': size };
    if (color) badgeStyle.color = color;

    const popoverStyle: Record<string, string> = {};
    if (color) popoverStyle['--tier-color'] = color;

    const trimmedDescription = (row.description || '').trim();
    const headlineNode: Mithril.Children = trimmedDescription
      ? m.trust(sanitiseDescription(trimmedDescription))
      : app.translator.trans('ramon-verified.lib.popover.headline');

    const learnMoreUrl = row.learnMoreUrl;

    return (
      <div className="VerifiedTier-preview">
        <div className="VerifiedTier-previewLine">
          <span className="VerifiedTier-previewName">{displayName}</span>

          <span className="VerifiedPopover-anchor" style={popoverStyle}>
            <span
              className="VerifiedBadge VerifiedBadge--inAnchor"
              style={badgeStyle}
              role="img"
              aria-label={ariaLabel}
              tabIndex={0}
            >
              {m.trust(getBadgeSvg())}
            </span>

            <span className="VerifiedPopover" role="tooltip">
              <span className="VerifiedPopover-arrow" aria-hidden="true" />

              <span className="VerifiedPopover-header">
                <span className="VerifiedPopover-headerIcon">
                  {m.trust(getBadgeSvg())}
                </span>
                <span className="VerifiedPopover-headerText">
                  {headlineNode}
                  {learnMoreUrl && /^https?:\/\//i.test(learnMoreUrl) && (
                    <>
                      {' '}
                      <a
                        className="VerifiedPopover-learnMore"
                        href={learnMoreUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={color ? { color } : undefined}
                      >
                        {app.translator.trans('ramon-verified.lib.popover.learn_more')}
                      </a>
                    </>
                  )}
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

                <span className="VerifiedPopover-meta">
                  {app.translator.trans('ramon-verified.lib.popover.verified_no_date')}
                </span>
              </span>
            </span>
          </span>

          <span className="VerifiedTier-previewMeta">
            {trans('preview.meta_sample')}
          </span>
        </div>

        <p className="VerifiedTier-previewHint">
          <i className="icon fas fa-mouse-pointer" aria-hidden="true" />
          {trans('settings.tiers.preview_hint')}
        </p>
      </div>
    );
  }
}
