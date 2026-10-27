import app from 'flarum/common/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';

import type Mithril from 'mithril';
import type MithrilStream from 'mithril/stream';

import { getConfiguredTiers, DEFAULT_TIER_ID, VerifiedTier } from '../utils/tiers';

export interface VerificationPromptResult {
  // null quando o prompt não pediu tier.
  tier: string | null;
  note: string;
}

export interface IVerificationPromptModalAttrs extends IInternalModalAttrs {
  promptTitle: Mithril.Children;
  noteLabel: Mithril.Children;
  confirmLabel: Mithril.Children;
  withTier: boolean;
  // Chamado uma única vez: com o resultado ao confirmar, null ao dispensar.
  resolve: (result: VerificationPromptResult | null) => void;
}

const trans = (key: string) => app.translator.trans(`ramon-verified.lib.verification_prompt.${key}`);

export default class VerificationPromptModal extends Modal<IVerificationPromptModalAttrs> {
  protected note!: MithrilStream<string>;
  protected tierId!: MithrilStream<string>;
  protected tiers: VerifiedTier[] = [];
  private settled = false;

  oninit(vnode: Mithril.Vnode<IVerificationPromptModalAttrs, this>) {
    super.oninit(vnode);

    this.note = Stream('');
    this.tiers = this.attrs.withTier ? getConfiguredTiers() : [];

    const fallback = this.tiers.find((t) => t.id === DEFAULT_TIER_ID) || this.tiers[0];
    this.tierId = Stream(fallback ? fallback.id : '');
  }

  className(): string {
    return 'VerificationPromptModal Modal--small';
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
          <textarea className="FormControl" rows={3} maxlength={1000} placeholder={extractText(trans('note_placeholder'))} bidi={this.note} />
        </div>

        <div className="Form-group">
          <Button className="Button Button--primary" onclick={() => this.confirm()}>
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
        <label>{trans('tier_label')}</label>
        <Select value={this.tierId()} options={options} onchange={(v: string) => this.tierId(v)} />
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

  // X, Esc e clique no backdrop passam todos por aqui.
  onremove(_vnode: Mithril.VnodeDOM<IVerificationPromptModalAttrs, this>): void {
    this.settle(null);
  }

  private settle(result: VerificationPromptResult | null): void {
    if (this.settled) return;
    this.settled = true;
    this.attrs.resolve(result);
  }
}
