import app from "flarum/common/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import Select from "flarum/common/components/Select";
import Stream from "flarum/common/utils/Stream";
import extractText from "flarum/common/utils/extractText";

import type Mithril from "mithril";
import type MithrilStream from "mithril/stream";

import {
  getConfiguredTiers,
  DEFAULT_TIER_ID,
  VerifiedTier,
} from "../utils/tiers";

export interface VerificationPromptResult {
  /** Selected tier id, or null when the prompt did not ask for a tier. */
  tier: string | null;
  /** Trimmed audit-log note. May be an empty string. */
  note: string;
}

export interface IVerificationPromptModalAttrs extends IInternalModalAttrs {
  /** Modal heading. */
  promptTitle: Mithril.Children;
  /** Field label describing what the note is for. */
  noteLabel: Mithril.Children;
  /** Primary button label. */
  confirmLabel: Mithril.Children;
  /** When true, show a tier selector and return its value. */
  withTier: boolean;
  /** Settled exactly once: the result on confirm, null on any dismissal. */
  resolve: (result: VerificationPromptResult | null) => void;
}

const trans = (key: string) =>
  app.translator.trans(`ramon-verified.lib.verification_prompt.${key}`);

/**
 * Coleta uma nota de auditoria e, opcionalmente, o tier — substitui os
 * `window.prompt` dos fluxos de verify/approve/revoke por uma UI consistente
 * com o resto da extensão. Resolve a promise do caller via `attrs.resolve`:
 * o resultado no confirm, `null` em qualquer cancelamento (X, Esc, backdrop).
 */
export default class VerificationPromptModal extends Modal<IVerificationPromptModalAttrs> {
  protected note!: MithrilStream<string>;
  protected tierId!: MithrilStream<string>;
  protected tiers: VerifiedTier[] = [];
  private settled = false;

  oninit(vnode: Mithril.Vnode<IVerificationPromptModalAttrs, this>) {
    super.oninit(vnode);

    this.note = Stream("");
    this.tiers = this.attrs.withTier ? getConfiguredTiers() : [];

    const fallback =
      this.tiers.find((t) => t.id === DEFAULT_TIER_ID) || this.tiers[0];
    this.tierId = Stream(fallback ? fallback.id : "");
  }

  className(): string {
    return "VerificationPromptModal Modal--small";
  }

  title(): Mithril.Children {
    return this.attrs.promptTitle;
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        {this.tiers.length > 1 ? this.tierField() : null}

        <div className="Form-group">
          <label>{this.attrs.noteLabel}</label>
          <textarea
            className="FormControl"
            rows={3}
            maxlength={1000}
            placeholder={extractText(trans("note_placeholder"))}
            bidi={this.note}
          />
        </div>

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            onclick={() => this.confirm()}
          >
            {this.attrs.confirmLabel}
          </Button>
        </div>
      </div>
    );
  }

  private tierField(): Mithril.Children {
    const options: Record<string, string> = {};
    this.tiers.forEach((t) => {
      options[t.id] = t.label;
    });

    return (
      <div className="Form-group">
        <label>{trans("tier_label")}</label>
        <Select
          value={this.tierId()}
          options={options}
          onchange={(v: string) => this.tierId(v)}
        />
      </div>
    );
  }

  private confirm(): void {
    this.settle({
      tier: this.attrs.withTier ? this.tierId() : null,
      note: this.note().trim(),
    });
    this.hide();
  }

  /**
   * Todo caminho de dismissal (X, Esc, clique no backdrop) remove o vnode e
   * cai aqui — quando o modal não foi confirmado, o caller recebe `null`.
   */
  onremove(_vnode: Mithril.VnodeDOM<IVerificationPromptModalAttrs, this>): void {
    this.settle(null);
  }

  private settle(result: VerificationPromptResult | null): void {
    if (this.settled) return;
    this.settled = true;
    this.attrs.resolve(result);
  }
}
