import app from "flarum/forum/app";

import VerifiedCelebrationModal from "./components/VerifiedCelebrationModal";

const MAX_RETRIES = 20; // 5s cap em 250ms ticks

/**
 * Mostra `VerifiedCelebrationModal` uma única vez quando o usuário acaba
 * de ser verificado (próximo page load depois da aprovação).
 *
 * Persistência via preferência server-side
 * `ramonVerifiedCelebrationShownAt` — armazena o `verifiedAt` para o qual
 * o modal já foi visto. Quando uma nova verificação acontece (revogação
 * + re-emissão), o `verifiedAt` muda e o modal aparece de novo uma vez.
 */
export default function addVerifiedCelebration(): void {
  setTimeout(() => tick(0), 0);
}

function tick(retries: number): void {
  const user = app.session && app.session.user;
  if (!user) return;

  if (!user.isVerified || !user.isVerified()) return;

  // Stamp = `verifiedAt` (timestamp) ou `'auto'` para auto-verified sem
  // timestamp. A preferência guarda o stamp do qual o modal já foi
  // visto; re-verificação produz stamp novo → modal reabre uma vez.
  const verifiedAt = user.verifiedAt ? user.verifiedAt() : null;
  let stamp = "auto";
  if (verifiedAt instanceof Date) {
    stamp = String(verifiedAt.getTime());
  } else if (typeof verifiedAt === "string" && verifiedAt) {
    stamp = verifiedAt;
  }

  const prefs = (user.preferences && user.preferences()) || null;
  const previousStamp = prefs
    ? String(
        (prefs as Record<string, unknown>).ramonVerifiedCelebrationShownAt ??
          "",
      )
    : "";
  if (previousStamp && previousStamp === stamp) return;

  if (!app.modal || typeof app.modal.show !== "function") {
    if (retries >= MAX_RETRIES) return;
    setTimeout(() => tick(retries + 1), 250);
    return;
  }

  app.modal.show(VerifiedCelebrationModal, { stamp });
}
