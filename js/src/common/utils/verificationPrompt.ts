import app from "flarum/common/app";

import type Mithril from "mithril";

import VerificationPromptModal, {
  VerificationPromptResult,
} from "../components/VerificationPromptModal";

export interface VerificationPromptOptions {
  /** Modal heading. */
  title: Mithril.Children;
  /** Field label describing what the note is for. */
  noteLabel: Mithril.Children;
  /** Primary button label. */
  confirmLabel: Mithril.Children;
  /** Ask for a verification tier alongside the note. */
  withTier?: boolean;
}

/**
 * Abre o `VerificationPromptModal` e resolve com a escolha do operador, ou
 * `null` se ele cancelar. Substitui os `window.prompt` dos fluxos de
 * verify/approve/revoke por uma UI consistente com o resto da extensão.
 */
export default function verificationPrompt(
  opts: VerificationPromptOptions,
): Promise<VerificationPromptResult | null> {
  return new Promise((resolve) => {
    app.modal.show(VerificationPromptModal, {
      promptTitle: opts.title,
      noteLabel: opts.noteLabel,
      confirmLabel: opts.confirmLabel,
      withTier: !!opts.withTier,
      resolve,
    });
  });
}
