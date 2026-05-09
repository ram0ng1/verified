import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';
import { getConfiguredTiers, DEFAULT_TIER_ID, VerifiedTier } from './tiers';

const trans = (key: string) => app.translator.trans(`ramon-verified.lib.tier_prompt.${key}`);

/**
 * Block the calling flow with a `window.prompt` that asks the operator which
 * tier to apply. Returns the chosen tier id, or null if cancelled / no tiers
 * are configured (caller should treat null as "abort").
 *
 * The shape is a numbered list rendered in the prompt body — no need for a
 * full modal here; verifying users is rare and `prompt` keeps the surface
 * area small (the same pattern the rest of the verification flow uses with
 * `window.prompt` for admin notes).
 */
export default function promptTier(): VerifiedTier | null {
  const tiers = getConfiguredTiers();

  if (tiers.length === 0) {
    app.alerts.show({ type: 'error' }, trans('no_tiers'));
    return null;
  }

  if (tiers.length === 1) {
    return tiers[0];
  }

  const lines: string[] = [extractText(trans('intro')), ''];
  tiers.forEach((tier, idx) => {
    lines.push(`${idx + 1}. ${tier.label} (${tier.id})`);
  });
  lines.push('');
  lines.push(extractText(trans('hint')));

  // Default suggestion: the configured `blue` tier if present, else the first.
  const defaultIdx = Math.max(
    0,
    tiers.findIndex((t) => t.id === DEFAULT_TIER_ID)
  );
  const answer = window.prompt(lines.join('\n'), tiers[defaultIdx].id);
  if (answer === null) return null;

  const trimmed = answer.trim().toLowerCase();
  if (!trimmed) return tiers[defaultIdx];

  // Accept either a number ("1") or the slug ("blue").
  const asInt = parseInt(trimmed, 10);
  if (Number.isFinite(asInt) && asInt >= 1 && asInt <= tiers.length) {
    return tiers[asInt - 1];
  }

  const bySlug = tiers.find((t) => t.id === trimmed);
  if (bySlug) return bySlug;

  app.alerts.show({ type: 'error' }, trans('invalid'));
  return null;
}
