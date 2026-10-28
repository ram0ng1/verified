import app from 'flarum/common/app';
import type User from 'flarum/common/models/User';

export interface VerifiedTier {
  id: string;
  label: string;
  color: string;
  description: string;
  learnMoreUrl: string;
  autoGroups: number[];
  badgeEnabled: boolean;
  badgeSvg: string;
}

export const DEFAULT_TIER_ID = 'blue';

export const BADGE_SVG_MAX = 8 * 1024;

let memoRaw: unknown = undefined;
let memoTiers: VerifiedTier[] = [];

// O valor bruto mantém a referência até o próximo save, então cada badge
// reaproveita a normalização em vez de refazê-la a cada redraw.
function memoised(raw: unknown, compute: () => VerifiedTier[]): VerifiedTier[] {
  if (raw !== memoRaw) {
    memoTiers = compute();
    memoRaw = raw;
  }
  return memoTiers;
}

export function getConfiguredTiers(): VerifiedTier[] {
  try {
    // As settings vêm antes do payload do fórum: o admin também recebe o
    // payload, mas ele só reflete o carregamento da página.
    const settings = (app as unknown as { data?: { settings?: Record<string, unknown> } }).data?.settings;
    const raw = settings?.['ramon-verified.tiers'];
    if (typeof raw === 'string' && raw.trim()) {
      return memoised(raw, () => {
        try {
          const parsed = JSON.parse(raw);
          if (Array.isArray(parsed)) {
            return parsed.map(normalise).filter(Boolean) as VerifiedTier[];
          }
        } catch (e) {
          warnDev('tier config JSON parse failed', e);
        }
        return [];
      });
    }

    // Fórum: a lista já vem parseada no payload.
    const v = app.forum?.attribute('ramonVerifiedTiers');
    if (Array.isArray(v)) {
      return memoised(v, () => v.map(normalise).filter(Boolean) as VerifiedTier[]);
    }
  } catch (e) {
    warnDev('tier config read failed', e);
  }

  return [];
}

function warnDev(msg: string, err: unknown): void {
  // Sem `typeof process`: o webpack substitui só a expressão completa.
  if (process.env.NODE_ENV === 'production') return;

  console.warn('[verified] ' + msg, err);
}

// Espelha TierConfig::sanitiseDescription: só <strong> e <em> sobrevivem, o
// resto é escapado. O preview do admin usa o texto ainda não salvo.
export function sanitiseDescription(raw: string): string {
  const text = (raw || '').trim();
  if (!text) return '';

  // Idempotente: o texto do fórum já chega escapado pelo servidor e passa por
  // aqui de novo, então `&` que já inicia uma entidade fica como está.
  const escaped = text
    .replace(/&(?!(?:amp|lt|gt|quot|#0?39);)/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  return escaped.replace(/&lt;(\/?)(strong|em)&gt;/gi, '<$1$2>');
}

function normalise(row: unknown): VerifiedTier | null {
  if (!row || typeof row !== 'object') return null;
  const r = row as Record<string, unknown>;

  const id = String(r.id || '')
    .trim()
    .toLowerCase();
  if (!/^[a-z0-9_-]{1,32}$/.test(id)) return null;

  const label = String(r.label || '').trim();
  if (!label) return null;

  const color = typeof r.color === 'string' && /^#[0-9a-f]{3,8}$/i.test(r.color.trim()) ? r.color.trim() : '';

  const description = typeof r.description === 'string' ? sanitiseDescription(r.description.trim().slice(0, 320)) : '';

  let learnMoreUrl = '';
  if (typeof r.learnMoreUrl === 'string') {
    const u = r.learnMoreUrl.trim();
    if (/^https?:\/\//i.test(u)) learnMoreUrl = u.slice(0, 500);
  }

  const autoGroups: number[] = [];
  if (Array.isArray(r.autoGroups)) {
    for (const g of r.autoGroups) {
      const n = parseInt(String(g), 10);
      if (Number.isFinite(n) && n > 0) autoGroups.push(n);
    }
  }

  let badgeEnabled = Boolean(r.badgeEnabled);
  let badgeSvg = '';
  if (badgeEnabled && typeof r.badgeSvg === 'string') {
    const candidate = r.badgeSvg;
    if (candidate && candidate.length <= BADGE_SVG_MAX) {
      badgeSvg = candidate;
    }
  }
  if (!badgeSvg) badgeEnabled = false;

  return {
    id,
    label,
    color,
    description,
    learnMoreUrl,
    autoGroups,
    badgeEnabled,
    badgeSvg,
  };
}

// O servidor já resolveu `verifiedTier` (atribuição manual vence a dos grupos);
// aqui só se cai no tier padrão quando ele falta ou não existe mais.
export function resolveTierForUser(user: User | null | undefined): VerifiedTier | null {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  const tiers = getConfiguredTiers();
  if (tiers.length === 0) return null;

  if (typeof user.verifiedTier === 'function') {
    const id = user.verifiedTier();
    if (id) {
      const found = tiers.find((t) => t.id === String(id).toLowerCase());
      if (found) return found;
    }
  }

  return tiers.find((t) => t.id === DEFAULT_TIER_ID) || tiers[0];
}

// null deixa o CSS usar a cor primária do tema.
export function getTierColor(tier: VerifiedTier | null): string | null {
  return (tier && tier.color) || null;
}
