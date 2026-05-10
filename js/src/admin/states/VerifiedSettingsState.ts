import { settings, saveSetting } from '../utils/settings';

const FLUSH_DEBOUNCE_MS = 400;

const SIZE_KEY = 'ramon-verified.badge_size';
const RETENTION_DAYS_KEY = 'ramon-verified.document_retention_days';
const RETENTION_KEY = 'ramon-verified.document_retention';

const RETENTION_MODES = ['keep', 'delete_immediate', 'delete_after_days'] as const;
export type RetentionMode = typeof RETENTION_MODES[number];

/**
 * Owns the debounced auto-save for the inline numeric controls
 * (badge size slider, retention-days input) and exposes a single helper
 * for switching the categorical retention mode (saved immediately).
 */
export default class VerifiedSettingsState {
  private _sizeTimer: ReturnType<typeof setTimeout> | null = null;
  private _retentionDaysTimer: ReturnType<typeof setTimeout> | null = null;

  /**
   * Update the badge size with optimistic UI feedback. Mutates the live
   * `--verified-size` CSS custom property so the preview card reflects the
   * change instantly, then debounces the API write.
   */
  queueSize(value: string): void {
    const num = parseFloat(value);
    if (!Number.isFinite(num)) return;
    const clamped = Math.max(0.6, Math.min(num, 3)).toFixed(2);

    settings()[SIZE_KEY] = clamped;
    if (typeof document !== 'undefined') {
      document.documentElement.style.setProperty('--verified-size', clamped + 'em');
    }

    if (this._sizeTimer) clearTimeout(this._sizeTimer);
    this._sizeTimer = setTimeout(
      () => saveSetting({ [SIZE_KEY]: clamped }),
      FLUSH_DEBOUNCE_MS
    );

    m.redraw();
  }

  queueRetentionDays(value: string): void {
    const num = parseInt(value, 10);
    if (!Number.isFinite(num)) return;
    const clamped = Math.max(1, Math.min(num, 3650));

    settings()[RETENTION_DAYS_KEY] = String(clamped);

    if (this._retentionDaysTimer) clearTimeout(this._retentionDaysTimer);
    this._retentionDaysTimer = setTimeout(
      () => saveSetting({ [RETENTION_DAYS_KEY]: String(clamped) }),
      FLUSH_DEBOUNCE_MS
    );

    m.redraw();
  }

  setRetentionMode(mode: string): void {
    const next: RetentionMode = (RETENTION_MODES as readonly string[]).includes(mode)
      ? (mode as RetentionMode)
      : 'keep';
    settings()[RETENTION_KEY] = next;
    saveSetting({ [RETENTION_KEY]: next });
    m.redraw();
  }

  retentionMode(): RetentionMode {
    const raw = String(settings()[RETENTION_KEY] ?? 'keep');
    return (RETENTION_MODES as readonly string[]).includes(raw) ? (raw as RetentionMode) : 'keep';
  }
}
