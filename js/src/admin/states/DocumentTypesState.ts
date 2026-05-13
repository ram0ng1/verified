import { settings, saveSetting } from "../utils/settings";

const SETTING_KEY = "ramon-verified.document_types";
const FLUSH_DEBOUNCE_MS = 400;

export interface DocumentTypeRow {
  id: string;
  label: string;
}

/**
 * Owns the configurable list of accepted document types
 * (stored as a JSON-encoded array under the `ramon-verified.document_types`
 * setting key).
 */
export default class DocumentTypesState {
  types: DocumentTypeRow[] = [];

  private _flushTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    this.types = this.parse(String(settings()[SETTING_KEY] ?? ""));
  }

  parse(raw: string): DocumentTypeRow[] {
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed)
        ? parsed
            .filter(
              (r: unknown): r is Record<string, unknown> =>
                !!r && typeof r === "object",
            )
            .map((r) => ({
              id: String(r.id || ""),
              label: String(r.label || ""),
            }))
        : [];
    } catch (e) {
      return [];
    }
  }

  serialize(): string {
    return JSON.stringify(
      this.types.filter((r) => r.id.trim() && r.label.trim()),
    );
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
    this.types = this.types.concat([{ id: "", label: "" }]);
    m.redraw();
  }

  remove(idx: number): void {
    this.types = this.types.filter((_, i) => i !== idx);
    this.flushNow();
    m.redraw();
  }

  update(idx: number, field: "id" | "label", value: string): void {
    this.types = this.types.map((r, i) =>
      i === idx ? { ...r, [field]: value } : r,
    );
    this.flushSoon();
    m.redraw();
  }
}
