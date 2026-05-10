import app from 'flarum/admin/app';

/**
 * Coerce common truthy raw values into a real boolean. Settings come back as
 * strings ('1', 'true') or numbers depending on how they were saved, and the
 * checkbox/switch components want a real boolean.
 */
export const isOn = (raw: unknown): boolean =>
  raw === true || raw === 'true' || raw === 1 || raw === '1';

/**
 * Direct handle on `app.data.settings`. Always returns the live object so
 * callers can mutate it for instant UI feedback before the POST resolves.
 */
export const settings = (): Record<string, unknown> =>
  ((app as unknown as { data: { settings: Record<string, unknown> } }).data.settings) || {};

export const getBool = (key: string): boolean => isOn(settings()[key]);
export const getStr = (key: string): string => String(settings()[key] ?? '');

/**
 * POST a partial settings update. Mirrors the avocado / Flarum core pattern
 * of saving on every change instead of using a submit button.
 */
export function saveSetting(payload: Record<string, unknown>): Promise<unknown> {
  const apiUrl = (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');
  return app.request({ method: 'POST', url: `${apiUrl}/settings`, body: payload });
}
