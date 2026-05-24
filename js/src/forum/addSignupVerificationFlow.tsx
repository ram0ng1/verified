import app from "flarum/forum/app";

import SignupVerificationConfirmationModal from "./components/SignupVerificationConfirmationModal";
import RequestVerificationModal from "./components/RequestVerificationModal";

const SESSION_KEY = "ramonVerifiedSignupIntent";
const RECENT_JOIN_WINDOW_MS = 5 * 60_000; // 5 min — protege contra flag órfão
const MAX_MODAL_RETRIES = 20; // 5s cap em 250ms ticks
const MAX_CLOSE_POLL_MS = 10 * 60_000; // 10 min: usuário pode ficar lendo o form

/**
 * Pós-signup orquestrador: consome a flag deixada por
 * `addSignupVerificationOption` em `sessionStorage` quando o usuário
 * marcou o checkbox de verificação no registro.
 *
 * **Política de criação**: a backend (`VerificationRequestService::create`)
 * agora exige só `assertRegistered()` — qualquer recém-cadastrado pode
 * abrir o pedido em um único passo, mesmo antes de confirmar o e-mail.
 * Admin pode endurecer via setting `gate_by_permission` (default off).
 *
 * Dois caminhos:
 * - **Documento exigido**: abre `RequestVerificationModal` (formulário
 *   com upload). Se o usuário fechar sem submeter, mostramos um info
 *   alert com instrução pra retentar em Configurações.
 * - **Documento não exigido**: cria via JSON:API com POST vazio e
 *   mostra o modal de confirmação.
 *
 * `sessionStorage` só é limpo depois da resolução do caminho — se o
 * usuário fechar a aba antes do reload completar, a flag sobrevive e
 * o próximo load (com `joinTime` ainda < 5 min) ressuscita o fluxo.
 */
export default function addSignupVerificationFlow(): void {
  setTimeout(() => run(0), 0);
}

function run(retries: number): void {
  let intent: string | null = null;
  try {
    intent = sessionStorage.getItem(SESSION_KEY);
  } catch {
    return;
  }
  if (intent !== "1") return;

  const user = app.session && app.session.user;
  if (!user) return;

  // O signup precisa ter sido recente. Sem isso, uma flag esquecida em
  // outra aba dispararia o fluxo para um login normal posterior.
  const joinedAt = user.joinTime ? user.joinTime() : null;
  if (!(joinedAt instanceof Date)) return;
  if (Date.now() - joinedAt.getTime() > RECENT_JOIN_WINDOW_MS) {
    clearIntent();
    return;
  }

  // Modal manager pronto? Se não, retenta com cap pra não ficar em loop
  // infinito caso algo dê errado no boot.
  if (!app.modal || typeof app.modal.show !== "function") {
    if (retries >= MAX_MODAL_RETRIES) return;
    setTimeout(() => run(retries + 1), 250);
    return;
  }

  const requiresDocument = !!app.forum.attribute(
    "ramonVerifiedRequireDocument",
  );

  if (requiresDocument) {
    handleDocumentFlow(user);
    return;
  }

  handleNoDocumentFlow(user);
}

function handleDocumentFlow(user: NonNullable<typeof app.session.user>): void {
  // Snapshot do estado pre-modal: se ainda for false depois que ele
  // fechar, o usuário desistiu sem submeter.
  const hadPendingBefore = userHasPending(user);

  app.modal.show(RequestVerificationModal);

  // sessionStorage só sai daqui — se o usuário recarregar antes de
  // abrir o modal (improvável, fluxo é síncrono pós-setTimeout), o
  // próximo boot retoma.
  clearIntent();

  // Polling para detectar fechamento do modal sem submit. Cap em 10 min
  // pra não vazar timer eterno caso a SPA seja recarregada com o modal
  // aberto.
  const started = Date.now();
  const handle = setInterval(() => {
    const stillOpen = !!app.modal.modal;
    const timedOut = Date.now() - started > MAX_CLOSE_POLL_MS;

    if (stillOpen && !timedOut) return;

    clearInterval(handle);

    if (timedOut) return;

    const stillNoPending =
      !hadPendingBefore && !userHasPending(app.session.user!);

    if (stillNoPending) {
      app.alerts.show(
        { type: "info" },
        app.translator.trans(
          "ramon-verified.forum.signup_confirmation.you_can_request_later",
        ),
      );
    }
  }, 400);
}

function handleNoDocumentFlow(
  user: NonNullable<typeof app.session.user>,
): void {
  app.store
    .createRecord("verification-requests")
    .save({})
    .then(() => {
      if (app.session.user) {
        app.session.user.pushAttributes({
          hasPendingVerificationRequest: true,
          canRequestVerification: false,
        });
      }
      clearIntent();
      app.modal.show(SignupVerificationConfirmationModal, {});
    })
    .catch((err: unknown) => {
      clearIntent();
      const detail =
        (err as any)?.response?.errors?.[0]?.detail ||
        app.translator.trans("ramon-verified.lib.errors.generic");
      app.alerts.show({ type: "error" }, detail);
    });
}

function userHasPending(user: NonNullable<typeof app.session.user>): boolean {
  return !!(
    user.hasPendingVerificationRequest && user.hasPendingVerificationRequest()
  );
}

function clearIntent(): void {
  try {
    sessionStorage.removeItem(SESSION_KEY);
  } catch {
    // não-fatal
  }
}
