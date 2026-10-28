import app from 'flarum/admin/app';

// O valor bruto chega como string ou número, conforme foi salvo.
export const isOn = (raw: unknown): boolean => raw === true || raw === 'true' || raw === 1 || raw === '1';

// Devolve o objeto vivo para quem chama poder alterá-lo antes do POST voltar.
export const settings = (): Record<string, unknown> => (app as unknown as { data: { settings: Record<string, unknown> } }).data.settings || {};

export const getBool = (key: string): boolean => isOn(settings()[key]);
export const getStr = (key: string): string => String(settings()[key] ?? '');

export function saveSetting(payload: Record<string, unknown>): Promise<unknown> {
  const apiUrl = (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');
  return app.request({
    method: 'POST',
    url: `${apiUrl}/settings`,
    body: payload,
  });
}
