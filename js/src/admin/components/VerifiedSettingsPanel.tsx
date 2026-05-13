import app from "flarum/admin/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import UploadImageButton from "flarum/common/components/UploadImageButton";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";

import getBadgeSvg, { resolveAssetUrl } from "../../common/utils/getBadgeSvg";
import VerifiedSettingsState from "../states/VerifiedSettingsState";
import { getBool, getStr } from "../utils/settings";
import AdminToggle from "./AdminToggle";
import DocumentTypesEditor from "./DocumentTypesEditor";
import EncryptionCard from "./EncryptionCard";
import TiersEditor from "./TiersEditor";
import VerificationRequestsSection from "./VerificationRequestsSection";

const trans = (key: string) =>
  app.translator.trans(`ramon-verified.admin.${key}`);

class SubDivider extends Component<ComponentAttrs> {
  view(): Mithril.Children {
    return <div className="VerifiedAdmin-divider" />;
  }
}

type PanelTab = "general" | "tiers" | "requests";

const TAB_DEFS: Array<{ id: PanelTab; icon: string; trans: string }> = [
  { id: "general", icon: "fas fa-cog", trans: "tabs.general" },
  { id: "tiers", icon: "fas fa-certificate", trans: "tabs.tiers" },
  { id: "requests", icon: "fas fa-inbox", trans: "tabs.requests" },
];

/**
 * Single-column, card-based admin panel for the Verified extension.
 *
 * Holds three tabs (general / tiers / requests) and delegates the inputs to
 * dedicated sub-components — every textbox and toggle on the page auto-saves
 * to the standard `/api/settings` endpoint, so there's no submit button.
 */
export default class VerifiedSettingsPanel extends Component<ComponentAttrs> {
  protected currentTab: PanelTab = "general";
  protected settingsState!: VerifiedSettingsState;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.settingsState = new VerifiedSettingsState();
  }

  view(): Mithril.Children {
    return (
      <div className="VerifiedAdmin">
        {this.tabBar()}
        {this.tabContent()}
      </div>
    );
  }

  tabBar(): Mithril.Children {
    return (
      <nav
        className="VerifiedAdmin-tabs"
        role="tablist"
        aria-label={extractText(trans("tabs.aria_label"))}
      >
        {TAB_DEFS.map((t) => {
          const active = this.currentTab === t.id;
          return (
            <button
              type="button"
              role="tab"
              aria-selected={active}
              key={t.id}
              className={
                "VerifiedAdmin-tab" +
                (active ? " VerifiedAdmin-tab--active" : "")
              }
              onclick={() => {
                this.currentTab = t.id;
                m.redraw();
              }}
            >
              <i className={"icon " + t.icon} />
              <span>{trans(t.trans)}</span>
            </button>
          );
        })}
      </nav>
    );
  }

  tabContent(): Mithril.Children {
    if (this.currentTab === "tiers") {
      return (
        <div className="VerifiedAdmin-tabContent VerifiedAdmin-tabContent--tiers">
          {this.tiersHeader()}
          <TiersEditor />
        </div>
      );
    }

    if (this.currentTab === "requests") {
      return (
        <div className="VerifiedAdmin-tabContent">{this.requestsCard()}</div>
      );
    }

    // General tab
    const requireDoc = getBool("ramon-verified.require_document");
    return (
      <div className="VerifiedAdmin-tabContent">
        {this.previewCard()}
        {this.appearanceCard()}
        {this.behaviourCard()}
        {requireDoc ? this.documentTypesCard() : null}
        <EncryptionCard />
      </div>
    );
  }

  tiersHeader(): Mithril.Children {
    return (
      <header className="VerifiedAdmin-tabHeader">
        <h2>{trans("settings.tiers.section_title")}</h2>
        <p>{trans("settings.tiers.section_help")}</p>
      </header>
    );
  }

  // ---- Preview card ------------------------------------------------------

  previewCard(): Mithril.Children {
    const sizeRaw = parseFloat(getStr("ramon-verified.badge_size"));
    const size = Number.isFinite(sizeRaw) && sizeRaw > 0 ? sizeRaw : 1.2;
    const showTooltip = getBool("ramon-verified.show_tooltip");

    const lineStyle: Record<string, string> = { fontSize: "14px" };

    const badgeStyle: Record<string, string> = {
      width: size + "em",
      height: size + "em",
    };

    const tooltipText = extractText(
      app.translator.trans("ramon-verified.lib.tooltip")
    );

    const badgeNode = showTooltip ? (
      <span className="VerifiedPopover-anchor">
        <span
          className="VerifiedBadge VerifiedBadge--inAnchor"
          style={badgeStyle}
          role="img"
          aria-label={tooltipText}
          tabIndex={0}
        >
          {m.trust(getBadgeSvg())}
        </span>
        {this.previewPopover()}
      </span>
    ) : (
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
            {app.session.user ? app.session.user.displayName() : "User"}
          </span>
          {badgeNode}
          <span className="VerifiedAdmin-preview-meta">
            {trans("preview.meta_sample")}
          </span>
        </div>
        <div className="helpText">{trans("preview.helper")}</div>
      </div>
    );
  }

  previewPopover(): Mithril.Children {
    const u = app.session.user;
    const username = u ? u.username() : "user";
    const displayName = u ? u.displayName() : "User";
    const avatarUrl = u && u.avatarUrl && u.avatarUrl();

    return (
      <span className="VerifiedPopover" role="tooltip">
        <span className="VerifiedPopover-arrow" aria-hidden="true" />

        <span className="VerifiedPopover-header">
          <span className="VerifiedPopover-headerIcon">
            {m.trust(getBadgeSvg())}
          </span>
          <span className="VerifiedPopover-headerText">
            {app.translator.trans("ramon-verified.lib.popover.headline")}
          </span>
        </span>

        <span className="VerifiedPopover-body">
          <span className="VerifiedPopover-user">
            <span className="VerifiedPopover-avatar">
              {avatarUrl ? (
                <img className="Avatar" src={avatarUrl} alt={displayName} />
              ) : (
                <span className="Avatar" aria-hidden="true">
                  {(username[0] || "U").toUpperCase()}
                </span>
              )}
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

  appearanceCard(): Mithril.Children {
    const path = getStr("ramon-verified.badge_svg_path");

    // The "custom color" toggle and color-picker were removed — colors live
    // per-tier now (Admin → Tiers → [tier] → Color), so a global color
    // override would just compete with whatever each tier's user has chosen.

    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans("settings.section_appearance")}</h3>
          <p className="helpText">
            {trans("settings.section_appearance_help")}
          </p>
        </header>

        {this.sizeSliderRow()}

        <SubDivider />

        <div className="VerifiedAdmin-row">
          <label className="VerifiedAdmin-label">
            {trans("settings.custom_svg_label")}
          </label>
          <UploadImageButton
            name="verified-badge"
            routePath="verified/badge-svg"
            value={path}
            url={resolveAssetUrl(path)}
          />
          <p className="helpText">{trans("settings.custom_svg_help")}</p>
        </div>
      </section>
    );
  }

  sizeSliderRow(): Mithril.Children {
    const raw = parseFloat(getStr("ramon-verified.badge_size"));
    const value = Number.isFinite(raw) && raw > 0 ? raw : 1.2;
    const clamped = Math.max(0.6, Math.min(value, 3));

    return (
      <div className="VerifiedAdmin-row">
        <label className="VerifiedAdmin-label">
          {trans("settings.badge_size_label")}
          <span className="VerifiedAdmin-sizeValue">{clamped.toFixed(2)}×</span>
        </label>
        <input
          type="range"
          className="VerifiedAdmin-rangeInput"
          min="0.6"
          max="3"
          step="0.05"
          value={clamped}
          oninput={(e: Event) =>
            this.settingsState.queueSize((e.target as HTMLInputElement).value)
          }
        />
        <p className="helpText">{trans("settings.badge_size_help")}</p>
      </div>
    );
  }

  // ---- Behaviour card ----------------------------------------------------

  behaviourCard(): Mithril.Children {
    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans("settings.section_behaviour")}</h3>
          <p className="helpText">{trans("settings.section_behaviour_help")}</p>
        </header>

        <AdminToggle
          settingKey="ramon-verified.requests_open"
          label={trans("settings.requests_open_label")}
          help={trans("settings.requests_open_help")}
        />
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.show_tooltip"
          label={trans("settings.show_tooltip_label")}
          help={trans("settings.show_tooltip_help")}
        />
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.require_document"
          label={trans("settings.require_document_label")}
          help={trans("settings.require_document_help")}
        />
        <SubDivider />
        {this.retentionRow()}
        <SubDivider />
        <AdminToggle
          settingKey="ramon-verified.lock_avatar"
          label={trans("settings.lock_avatar_label")}
          help={trans("settings.lock_avatar_help")}
        />
      </section>
    );
  }

  retentionRow(): Mithril.Children {
    const mode = this.settingsState.retentionMode();
    const daysRaw = parseInt(
      getStr("ramon-verified.document_retention_days"),
      10
    );
    const days = Number.isFinite(daysRaw) && daysRaw > 0 ? daysRaw : 30;

    return (
      <div className="Form-group VerifiedAdmin-row">
        <label className="VerifiedAdmin-label">
          {trans("settings.retention_label")}
        </label>
        <select
          className="FormControl"
          value={mode}
          onchange={(e: Event) =>
            this.settingsState.setRetentionMode(
              (e.target as HTMLSelectElement).value
            )
          }
        >
          <option value="keep">
            {extractText(trans("settings.retention_keep"))}
          </option>
          <option value="delete_immediate">
            {extractText(trans("settings.retention_delete_immediate"))}
          </option>
          <option value="delete_after_days">
            {extractText(trans("settings.retention_delete_after_days"))}
          </option>
        </select>

        {mode === "delete_after_days" && (
          <div className="VerifiedAdmin-subGroup VerifiedAdmin-retentionDays">
            <label className="VerifiedAdmin-label">
              {trans("settings.retention_days_label")}
            </label>
            <input
              type="number"
              className="FormControl"
              min="1"
              max="3650"
              step="1"
              value={days}
              oninput={(e: Event) =>
                this.settingsState.queueRetentionDays(
                  (e.target as HTMLInputElement).value
                )
              }
            />
            <p className="helpText">{trans("settings.retention_days_help")}</p>
          </div>
        )}

        <p className="helpText">{trans("settings.retention_help")}</p>
      </div>
    );
  }

  // ---- Document types card ----------------------------------------------

  documentTypesCard(): Mithril.Children {
    return (
      <section className="VerifiedAdmin-card">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans("settings.section_document_types")}</h3>
          <p className="helpText">
            {trans("settings.section_document_types_help")}
          </p>
        </header>

        <DocumentTypesEditor />
      </section>
    );
  }

  // ---- Requests card -----------------------------------------------------

  requestsCard(): Mithril.Children {
    return (
      <section className="VerifiedAdmin-card VerifiedAdmin-card--requests">
        <VerificationRequestsSection />
      </section>
    );
  }
}
