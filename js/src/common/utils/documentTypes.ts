import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';

export interface DocumentType {
  id: string;
  label: string;
}

const FALLBACK_IDS = ['rg', 'cpf', 'passport', 'driver', 'other'];

/**
 * Lista usada só quando o admin esvaziou a configuração: os mesmos ids de
 * sempre, com rótulos traduzidos.
 */
function fallback(): DocumentType[] {
  return FALLBACK_IDS.map((id) => ({
    id,
    label: extractText(app.translator.trans(`ramon-verified.lib.document_types.${id}`)),
  }));
}

/**
 * Os tipos de documento aceitos. No admin vêm das settings ao vivo (o JSON
 * cru); no fórum, do payload.
 */
export function getDocumentTypes(): DocumentType[] {
  const settings = (app as unknown as { data?: { settings?: Record<string, unknown> } }).data?.settings;
  const raw = settings?.['ramon-verified.document_types'];

  let list: unknown = app.forum?.attribute('ramonVerifiedDocumentTypes');
  if (typeof raw === 'string') {
    try {
      list = JSON.parse(raw);
    } catch {
      list = [];
    }
  }

  const types = Array.isArray(list) ? list.filter((t): t is DocumentType => !!t && typeof t.id === 'string' && typeof t.label === 'string') : [];

  return types.length > 0 ? types : fallback();
}

export function documentTypeLabel(type: string | null | undefined): string {
  if (!type) return '';

  return getDocumentTypes().find((t) => t.id === type)?.label ?? type;
}
