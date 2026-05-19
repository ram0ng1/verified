import app from "flarum/admin/app";
import Group from "flarum/common/models/Group";
import sortGroups from "flarum/common/utils/sortGroups";
import extractText from "flarum/common/utils/extractText";

import { settings, saveSetting } from "../utils/settings";

const trans = (key: string) =>
  app.translator.trans(`ramon-verified.admin.${key}`);

const SETTING_KEY = "ramon-verified.tiers";
const FLUSH_DEBOUNCE_MS = 400;

export const BADGE_SVG_MAX = 8 * 1024;

export interface TierRow {
  id: string;
  label: string;
  color: string;
  description: string;
  learnMoreUrl: string;
  autoGroups: number[];
  badgeEnabled: boolean;
  badgeSvg: string;
}

function emptyRow(): TierRow {
  return {
    id: "",
    label: "",
    color: "#1d9bf0",
    description: "",
    learnMoreUrl: "",
    autoGroups: [],
    badgeEnabled: false,
    badgeSvg: "",
  };
}

/**
 * Owns the tier-list state for the admin Tiers tab. Handles parsing the
 * stored JSON setting, debounced auto-save, and the row-level mutations
 * (add / remove / update / toggle group). The component is purely
 * presentational against this state.
 */
export default class TiersEditorState {
  rows: TierRow[] = [];
  expandedIdx: number | null = null;
  /** Cached, sorted, non-guest group list. Read once in the constructor — admin groups don't change while the panel is mounted. */
  readonly groups: Group[];

  private _flushTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    this.rows = this.parse(String(settings()[SETTING_KEY] ?? ""));
    this.groups = sortGroups(
      app.store.all<Group>("groups").filter((g) => g.id() !== Group.GUEST_ID),
    );
  }

  parse(raw: string): TierRow[] {
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed.map((r: unknown) => {
        const row = (r && typeof r === "object" ? r : {}) as Record<
          string,
          unknown
        >;
        const badgeSvgRaw =
          typeof row.badgeSvg === "string" ? row.badgeSvg : "";
        const badgeEnabled =
          Boolean(row.badgeEnabled) && badgeSvgRaw.trim().length > 0;

        return {
          id: String(row.id || "").trim(),
          label: String(row.label || "").trim(),
          color: String(row.color || "").trim(),
          description: String(row.description || "").trim(),
          learnMoreUrl: String(row.learnMoreUrl || "").trim(),
          autoGroups: Array.isArray(row.autoGroups)
            ? row.autoGroups
                .map((g) => parseInt(String(g), 10))
                .filter((n) => Number.isFinite(n) && n > 0)
            : [],
          badgeEnabled,
          badgeSvg: badgeSvgRaw.length > BADGE_SVG_MAX ? "" : badgeSvgRaw,
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
        .map((r) => {
          const trimmedSvg = (r.badgeSvg || "").trim();
          const badgeEnabled = r.badgeEnabled && trimmedSvg.length > 0;
          return {
            id: r.id.trim().toLowerCase(),
            label: r.label.trim(),
            color: r.color.trim(),
            description: r.description.trim(),
            learnMoreUrl: this.normaliseUrl(r.learnMoreUrl),
            autoGroups: r.autoGroups,
            badgeEnabled,
            badgeSvg: badgeEnabled ? trimmedSvg : "",
          };
        }),
    );
  }

  /**
   * Trim and auto-prepend `https://` when the admin types a URL without a
   * protocol. The backend `TierConfig` regex only accepts http/https URLs,
   * so silently dropping bare hostnames was a sharp edge — admins typed
   * `policy.example.com` and the link disappeared on save with no feedback.
   */
  normaliseUrl(raw: string): string {
    const trimmed = (raw || "").trim();
    if (!trimmed) return "";
    if (/^https?:\/\//i.test(trimmed)) return trimmed;
    // If it already has another scheme (mailto:, ftp:, …) leave it alone —
    // it'll be rejected server-side, but we shouldn't silently rewrite it.
    if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) return trimmed;
    return "https://" + trimmed;
  }

  flushNow(): void {
    const raw = this.serialize();
    settings()[SETTING_KEY] = raw;
    saveSetting({ [SETTING_KEY]: raw });
  }

  flushSoon(): void {
    if (this._flushTimer) clearTimeout(this._flushTimer);
    this._flushTimer = setTimeout(() => this.flushNow(), FLUSH_DEBOUNCE_MS);
  }

  add(): void {
    this.rows = [...this.rows, emptyRow()];
    this.expandedIdx = this.rows.length - 1;
    m.redraw();
  }

  remove(idx: number): void {
    if (!window.confirm(extractText(trans("settings.tiers.remove_confirm"))))
      return;
    this.rows = this.rows.filter((_, i) => i !== idx);
    if (this.expandedIdx === idx) this.expandedIdx = null;
    else if (this.expandedIdx !== null && this.expandedIdx > idx)
      this.expandedIdx -= 1;
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
}
