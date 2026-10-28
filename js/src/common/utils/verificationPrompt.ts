import app from 'flarum/common/app';

import type Mithril from 'mithril';

import type { VerificationPromptResult } from '../components/VerificationPromptModal';

export interface VerificationPromptOptions {
  title: Mithril.Children;
  noteLabel: Mithril.Children;
  confirmLabel: Mithril.Children;
  withTier?: boolean;
}

// Resolve null quando o operador cancela.
export default function verificationPrompt(opts: VerificationPromptOptions): Promise<VerificationPromptResult | null> {
  return new Promise((resolve) => {
    app.modal.show(() => import('../components/VerificationPromptModal'), {
      promptTitle: opts.title,
      noteLabel: opts.noteLabel,
      confirmLabel: opts.confirmLabel,
      withTier: !!opts.withTier,
      resolve,
    });
  });
}
