import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Button from "flarum/common/components/Button";
import Group from "flarum/common/models/Group";
import GroupBadge from "flarum/common/components/GroupBadge";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import getBadgeSvg, { getBadgeSize } from "../../common/utils/getBadgeSvg";
import {
  sanitiseDescription,
  type VerifiedTier,
} from "../../common/utils/tiers";
import TiersEditorState, {
  BADGE_SVG_MAX,
  TierRow,
} from "../states/TiersEditorState";
import { wrapTextareaSelection } from "../utils/textareaMarkup";

const trans = (key: string) =>
  app.translator.trans(`ramon-verified.admin.${key}`);

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
  protected tiers!: TiersEditorState;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.tiers = new TiersEditorState();
  }

  view(): Mithril.Children {
    if (this.tiers.rows.length === 0) {
      return this.emptyState();
    }

    return (
      <div className="VerifiedTiers">
        <ol className="VerifiedTiers-list">
          {this.tiers.rows.map((row, idx) => this.renderCard(row, idx))}
        </ol>

        <Button
          className="Button VerifiedTiers-add"
          icon="fas fa-plus"
          onclick={() => this.tiers.add()}
        >
          {trans("settings.tiers.add")}
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
        <h3 className="VerifiedTiers-emptyTitle">
          {trans("settings.tiers.empty_title")}
        </h3>
        <p className="VerifiedTiers-emptyText">
          {trans("settings.tiers.empty")}
        </p>
        <Button
          className="Button Button--primary VerifiedTiers-add"
          icon="fas fa-plus"
          onclick={() => this.tiers.add()}
        >
          {trans("settings.tiers.add_first")}
        </Button>
      </div>
    );
  }

  renderCard(row: TierRow, idx: number): Mithril.Children {
    const isExpanded = this.tiers.expandedIdx === idx;
    const swatchColor = /^#[0-9a-f]{3,8}$/i.test(row.color)
      ? row.color
      : "var(--primary-color)";
    const groupCount = row.autoGroups.length;
    const swatchTier = this.rowAsTier(row);
    const swatchHasCustomBadge =
      swatchTier.badgeEnabled && !!swatchTier.badgeSvg;

    return (
      <li
        className={
          "VerifiedTier" + (isExpanded ? " VerifiedTier--expanded" : "")
        }
        style={{ "--tier-color": swatchColor } as Record<string, string>}
        key={idx}
      >
        <button
          type="button"
          className="VerifiedTier-header"
          aria-expanded={isExpanded}
          onclick={() => this.tiers.toggleExpand(idx)}
        >
          <span
            className={
              "VerifiedTier-swatch" +
              (swatchHasCustomBadge ? " VerifiedTier-swatch--custom" : "")
            }
            aria-hidden="true"
          >
            {swatchHasCustomBadge ? (
              m.trust(getBadgeSvg(swatchTier))
            ) : (
              <i className="icon fas fa-certificate" />
            )}
          </span>

          <span className="VerifiedTier-titleBlock">
            <span className="VerifiedTier-label">
              {row.label || trans("settings.tiers.unnamed")}
            </span>
            <span className="VerifiedTier-meta">
              <code className="VerifiedTier-id">
                {row.id || trans("settings.tiers.no_id")}
              </code>
              {groupCount > 0 && (
                <span className="VerifiedTier-groupsCount">
                  <i className="icon fas fa-users" />
                  {groupCount}
                </span>
              )}
              {row.learnMoreUrl && (
                <span
                  className="VerifiedTier-linkChip"
                  title={row.learnMoreUrl}
                >
                  <i className="icon fas fa-link" />
                </span>
              )}
            </span>
          </span>

          <span className="VerifiedTier-chevron" aria-hidden="true">
            <i
              className={
                "icon fas " + (isExpanded ? "fa-chevron-up" : "fa-chevron-down")
              }
            />
          </span>
        </button>

        {isExpanded && this.renderEditForm(row, idx)}
      </li>
    );
  }

  renderEditForm(row: TierRow, idx: number): Mithril.Children {
    const idValid = /^[a-z0-9_-]{1,32}$/.test(row.id.trim().toLowerCase());
    const urlValid =
      !!row.learnMoreUrl && /^https?:\/\//i.test(row.learnMoreUrl);
    const charCount = (row.description || "").length;
    const charCountClass =
      charCount >= 280
        ? "VerifiedTier-charCount VerifiedTier-charCount--max"
        : charCount >= 240
          ? "VerifiedTier-charCount VerifiedTier-charCount--warn"
          : "VerifiedTier-charCount";

    return (
      <div className="VerifiedTier-body">
        {this.renderPreviewBanner(row)}

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">
            {trans("settings.tiers.section_identity")}
          </h4>
          <div className="VerifiedTier-grid">
            <div className="VerifiedTier-field">
              <label className="VerifiedTier-fieldLabel">
                {trans("settings.tiers.id_label")}
              </label>
              <input
                type="text"
                className={
                  "FormControl" + (row.id && !idValid ? " is-invalid" : "")
                }
                value={row.id}
                placeholder="blue"
                spellcheck="false"
                autocomplete="off"
                oninput={(e: Event) =>
                  this.tiers.update(idx, {
                    id: (e.target as HTMLInputElement).value,
                  })
                }
              />
              <p className="VerifiedTier-fieldHelp">
                {trans("settings.tiers.id_help")}
              </p>
            </div>

            <div className="VerifiedTier-field">
              <label className="VerifiedTier-fieldLabel">
                {trans("settings.tiers.label_label")}
              </label>
              <input
                type="text"
                className="FormControl"
                value={row.label}
                placeholder={extractText(
                  trans("settings.tiers.label_placeholder"),
                )}
                oninput={(e: Event) =>
                  this.tiers.update(idx, {
                    label: (e.target as HTMLInputElement).value,
                  })
                }
              />
            </div>
          </div>
        </div>

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">
            {trans("settings.tiers.section_appearance")}
          </h4>
          <div className="VerifiedTier-field">
            <label className="VerifiedTier-fieldLabel">
              {trans("settings.tiers.color_label")}
            </label>
            <div className="VerifiedTier-colorRow">
              <input
                type="color"
                className="VerifiedTier-colorPicker"
                value={
                  /^#[0-9a-f]{6}$/i.test(row.color) ? row.color : "#1d9bf0"
                }
                oninput={(e: Event) =>
                  this.tiers.update(idx, {
                    color: (e.target as HTMLInputElement).value,
                  })
                }
              />
              <input
                type="text"
                className="FormControl VerifiedTier-colorHex"
                value={row.color}
                placeholder="#1d9bf0"
                spellcheck="false"
                autocomplete="off"
                oninput={(e: Event) =>
                  this.tiers.update(idx, {
                    color: (e.target as HTMLInputElement).value,
                  })
                }
              />
            </div>
          </div>
        </div>

        {this.renderBadgeSection(row, idx)}

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">
            {trans("settings.tiers.section_popover")}
          </h4>

          <div className="VerifiedTier-field">
            <div className="VerifiedTier-fieldHeader">
              <label className="VerifiedTier-fieldLabel">
                {trans("settings.tiers.description_label")}
              </label>
              <span className={charCountClass}>{charCount}/280</span>
            </div>
            <div className="VerifiedTier-descToolbar">
              <button
                type="button"
                className="VerifiedTier-descBtn"
                title={extractText(
                  trans("settings.tiers.description_bold_title"),
                )}
                aria-label={extractText(
                  trans("settings.tiers.description_bold_title"),
                )}
                onclick={(e: Event) => this.wrapDescription(e, idx, "strong")}
              >
                <strong>B</strong>
              </button>
              <button
                type="button"
                className="VerifiedTier-descBtn"
                title={extractText(
                  trans("settings.tiers.description_italic_title"),
                )}
                aria-label={extractText(
                  trans("settings.tiers.description_italic_title"),
                )}
                onclick={(e: Event) => this.wrapDescription(e, idx, "em")}
              >
                <em>I</em>
              </button>
              <span className="VerifiedTier-descToolbarHint">
                {trans("settings.tiers.description_toolbar_hint")}
              </span>
            </div>
            <textarea
              className="FormControl VerifiedTier-descArea"
              data-tier-idx={idx}
              rows={2}
              maxlength={320}
              value={row.description}
              placeholder={extractText(
                trans("settings.tiers.description_placeholder"),
              )}
              oninput={(e: Event) =>
                this.tiers.update(idx, {
                  description: (e.target as HTMLTextAreaElement).value,
                })
              }
            />
            <p className="VerifiedTier-fieldHelp">
              {trans("settings.tiers.description_help")}
            </p>
          </div>

          <div className="VerifiedTier-field">
            <label className="VerifiedTier-fieldLabel">
              {trans("settings.tiers.learn_more_label")}
            </label>
            <div className="VerifiedTier-urlRow">
              <input
                type="url"
                className="FormControl"
                value={row.learnMoreUrl}
                placeholder="https://exemplo.com/politica-de-verificacao"
                oninput={(e: Event) =>
                  this.tiers.update(idx, {
                    learnMoreUrl: (e.target as HTMLInputElement).value,
                  })
                }
              />
              {urlValid && (
                <a
                  className="VerifiedTier-urlOpen"
                  href={row.learnMoreUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={extractText(trans("settings.tiers.learn_more_open"))}
                  aria-label={extractText(
                    trans("settings.tiers.learn_more_open"),
                  )}
                >
                  <i className="icon fas fa-arrow-up-right-from-square" />
                </a>
              )}
            </div>
            <p className="VerifiedTier-fieldHelp">
              {trans("settings.tiers.learn_more_help")}
            </p>
          </div>
        </div>

        <div className="VerifiedTier-section">
          <h4 className="VerifiedTier-sectionTitle">
            {trans("settings.tiers.section_assignment")}
          </h4>

          <div className="VerifiedTier-field">
            <p className="VerifiedTier-fieldHelp VerifiedTier-fieldHelp--lead">
              {trans("settings.tiers.auto_groups_help")}
            </p>
            <div className="VerifiedTier-chips">
              {this.tiers.groups.length === 0 ? (
                <p className="VerifiedTier-fieldHelp">
                  {trans("settings.tiers.auto_groups_empty")}
                </p>
              ) : (
                this.tiers.groups.map((group: Group) => {
                  const gid = parseInt(String(group.id()), 10);
                  const checked = row.autoGroups.indexOf(gid) !== -1;
                  return (
                    <button
                      type="button"
                      key={gid}
                      className={
                        "VerifiedTier-chip" +
                        (checked ? " VerifiedTier-chip--on" : "")
                      }
                      aria-pressed={checked}
                      onclick={() => this.tiers.toggleGroup(idx, gid)}
                    >
                      <GroupBadge group={group} label={null} />
                      <span>{group.namePlural()}</span>
                      {checked && (
                        <i className="icon fas fa-check VerifiedTier-chipCheck" />
                      )}
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
            onclick={() => this.tiers.remove(idx)}
          >
            <i className="icon fas fa-trash" /> {trans("settings.tiers.remove")}
          </button>
          <button
            type="button"
            className="Button Button--primary"
            onclick={() => this.tiers.toggleExpand(idx)}
          >
            <i className="icon fas fa-check" /> {trans("settings.tiers.done")}
          </button>
        </div>
      </div>
    );
  }

  /**
   * Constrói a `VerifiedTier` virtual a partir da row em edição para que o
   * preview e os consumidores de `getBadgeSvg(tier)` reflitam o estado
   * presente da textarea (sem precisar recarregar a página).
   */
  protected rowAsTier(row: TierRow): VerifiedTier {
    const trimmedSvg = (row.badgeSvg || "").trim();
    const badgeEnabled = row.badgeEnabled && trimmedSvg.length > 0;

    return {
      id: row.id.trim().toLowerCase(),
      label: row.label.trim(),
      color: row.color.trim(),
      description: row.description.trim(),
      learnMoreUrl: row.learnMoreUrl.trim(),
      autoGroups: row.autoGroups,
      badgeEnabled,
      badgeSvg: badgeEnabled ? trimmedSvg : "",
    };
  }

  /**
   * Seção opt-in para SVG customizado por tier. UI minimal: toggle, drop
   * zone com preview do badge ativo (herdando a cor do tier via
   * `currentColor`), botão "Substituir" + "Remover". O conteúdo bruto do
   * SVG fica armazenado em estado mas nunca exposto como textarea — o
   * admin trabalha com arquivos, não markup.
   */
  renderBadgeSection(row: TierRow, idx: number): Mithril.Children {
    const fileInputId = `verified-tier-badge-file-${idx}`;
    const trimmedSvg = (row.badgeSvg || "").trim();
    const hasSvg = trimmedSvg.length > 0 && trimmedSvg.length <= BADGE_SVG_MAX;
    const previewTier = this.rowAsTier(row);
    const sizeKB = hasSvg ? (trimmedSvg.length / 1024).toFixed(1) : "0";

    return (
      <div className="VerifiedTier-section VerifiedTier-section--badge">
        <div className="VerifiedTier-sectionHeader">
          <div>
            <h4 className="VerifiedTier-sectionTitle">
              {trans("settings.tiers.section_badge")}
            </h4>
            <p className="VerifiedTier-sectionDesc">
              {trans("settings.tiers.badge_toggle_help")}
            </p>
          </div>

          <label
            className={
              "VerifiedTier-switch" +
              (row.badgeEnabled ? " VerifiedTier-switch--on" : "")
            }
            title={extractText(trans("settings.tiers.badge_toggle_label"))}
          >
            <input
              type="checkbox"
              checked={row.badgeEnabled}
              onchange={(e: Event) => {
                const enabled = (e.target as HTMLInputElement).checked;
                this.tiers.update(idx, {
                  badgeEnabled: enabled,
                  badgeSvg: enabled ? row.badgeSvg : "",
                });
              }}
            />
            <span className="VerifiedTier-switchTrack" aria-hidden="true">
              <span className="VerifiedTier-switchThumb" />
            </span>
            <span className="VerifiedTier-switchLabel">
              {row.badgeEnabled
                ? trans("settings.tiers.badge_switch_on")
                : trans("settings.tiers.badge_switch_off")}
            </span>
          </label>
        </div>

        {row.badgeEnabled && (
          <div className="VerifiedTier-badgePicker">
            <input
              type="file"
              id={fileInputId}
              accept="image/svg+xml,.svg"
              hidden
              onchange={(e: Event) => this.handleBadgeFile(e, idx)}
            />

            {hasSvg ? (
              <div className="VerifiedTier-badgeSlot">
                <span
                  className="VerifiedTier-badgeSlotPreview"
                  aria-hidden="true"
                >
                  {m.trust(getBadgeSvg(previewTier))}
                </span>
                <span className="VerifiedTier-badgeSlotInfo">
                  <strong>{trans("settings.tiers.badge_loaded")}</strong>
                  <span className="VerifiedTier-badgeSlotMeta">
                    {sizeKB} KB · {trans("settings.tiers.badge_size_max")}
                  </span>
                </span>
                <span className="VerifiedTier-badgeSlotActions">
                  <label
                    className="Button Button--text VerifiedTier-badgeSlotBtn"
                    htmlFor={fileInputId}
                  >
                    <i className="icon fas fa-rotate" />{" "}
                    {trans("settings.tiers.badge_replace")}
                  </label>
                  <button
                    type="button"
                    className="Button Button--text VerifiedTier-badgeSlotBtn VerifiedTier-badgeSlotBtn--danger"
                    onclick={() => this.tiers.update(idx, { badgeSvg: "" })}
                  >
                    <i className="icon fas fa-trash" />{" "}
                    {trans("settings.tiers.badge_remove")}
                  </button>
                </span>
              </div>
            ) : (
              <label className="VerifiedTier-dropzone" htmlFor={fileInputId}>
                <span className="VerifiedTier-dropzoneIcon" aria-hidden="true">
                  <i className="icon fas fa-cloud-arrow-up" />
                </span>
                <span className="VerifiedTier-dropzoneText">
                  <strong>
                    {trans("settings.tiers.badge_dropzone_title")}
                  </strong>
                  <span>{trans("settings.tiers.badge_dropzone_subtitle")}</span>
                </span>
              </label>
            )}
          </div>
        )}
      </div>
    );
  }

  /**
   * Lê o SVG do file picker, valida tamanho, descarta input se acima do
   * cap antes mesmo de tocar no estado. Sanitização real acontece no
   * `TierConfig::parse` server-side; o mirror cliente em
   * `tiers.ts`/`sanitizeSvg` reescreve `fill` para `currentColor` para o
   * preview herdar a cor do tier.
   */
  protected handleBadgeFile(e: Event, idx: number): void {
    const input = e.target as HTMLInputElement;
    const file = input.files && input.files[0];
    if (!file) return;

    if (file.size > BADGE_SVG_MAX) {
      alert(extractText(trans("settings.tiers.badge_svg_too_large")));
      input.value = "";
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || "").trim();
      if (!text || text.length > BADGE_SVG_MAX) {
        alert(extractText(trans("settings.tiers.badge_svg_too_large")));
        return;
      }
      this.tiers.update(idx, { badgeSvg: text, badgeEnabled: true });
    };
    reader.readAsText(file);
    input.value = "";
  }

  /**
   * Toolbar action: find the field's textarea via the click target's closest
   * ancestor, wrap its selection in `<tag>` markup, and push the new value
   * into the row state. The DOM round-trip is needed because we want to
   * preserve the caret position right after the inserted markup.
   */
  wrapDescription(e: Event, idx: number, tag: "strong" | "em"): void {
    e.preventDefault();

    const button = e.currentTarget as HTMLElement;
    const wrapper = button.closest(".VerifiedTier-field");
    const ta =
      wrapper &&
      (wrapper.querySelector(
        "textarea.VerifiedTier-descArea",
      ) as HTMLTextAreaElement | null);
    if (!ta) return;

    const next = wrapTextareaSelection(ta, tag);
    this.tiers.update(idx, { description: next });
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
    const previewTier = this.rowAsTier(row);
    const u = app.session.user;
    if (!u) return null;
    const username = u.username();
    const displayName = u.displayName();
    const avatarUrl = u.avatarUrl && u.avatarUrl();
    const ariaLabel = row.label || extractText(trans("settings.tiers.unnamed"));

    const badgeStyle: Record<string, string> = { "--verified-size": size };
    if (color) badgeStyle.color = color;

    const popoverStyle: Record<string, string> = {};
    if (color) popoverStyle["--tier-color"] = color;

    const trimmedDescription = (row.description || "").trim();
    const headlineNode: Mithril.Children = trimmedDescription
      ? m.trust(sanitiseDescription(trimmedDescription))
      : app.translator.trans("ramon-verified.lib.popover.headline");

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
              {m.trust(getBadgeSvg(previewTier))}
            </span>

            <span className="VerifiedPopover" role="tooltip">
              <span className="VerifiedPopover-arrow" aria-hidden="true" />

              <span className="VerifiedPopover-header">
                <span className="VerifiedPopover-headerIcon">
                  {m.trust(getBadgeSvg(previewTier))}
                </span>
                <span className="VerifiedPopover-headerText">
                  {headlineNode}
                  {learnMoreUrl && /^https?:\/\//i.test(learnMoreUrl) && (
                    <>
                      {" "}
                      <a
                        className="VerifiedPopover-learnMore"
                        href={learnMoreUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={color ? { color } : undefined}
                      >
                        {app.translator.trans(
                          "ramon-verified.lib.popover.learn_more",
                        )}
                      </a>
                    </>
                  )}
                </span>
              </span>

              <span className="VerifiedPopover-body">
                <span className="VerifiedPopover-user">
                  <span className="VerifiedPopover-avatar">
                    {avatarUrl ? (
                      <img
                        className="Avatar"
                        src={avatarUrl}
                        alt={displayName}
                      />
                    ) : (
                      <span className="Avatar" aria-hidden="true">
                        {(username[0] || "U").toUpperCase()}
                      </span>
                    )}
                  </span>
                  <span className="VerifiedPopover-userText">
                    <span className="VerifiedPopover-username">{username}</span>
                    <span className="VerifiedPopover-displayName">
                      {displayName}
                    </span>
                  </span>
                </span>

                <span className="VerifiedPopover-meta">
                  {app.translator.trans(
                    "ramon-verified.lib.popover.verified_no_date",
                  )}
                </span>
              </span>
            </span>
          </span>

          <span className="VerifiedTier-previewMeta">
            {trans("preview.meta_sample")}
          </span>
        </div>

        <p className="VerifiedTier-previewHint">
          <i className="icon fas fa-mouse-pointer" aria-hidden="true" />
          {trans("settings.tiers.preview_hint")}
        </p>
      </div>
    );
  }
}
