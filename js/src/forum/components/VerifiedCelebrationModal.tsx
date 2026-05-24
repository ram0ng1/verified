import app from "flarum/forum/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import type Mithril from "mithril";

import getBadgeSvg from "../../common/utils/getBadgeSvg";

export interface IVerifiedCelebrationModalAttrs extends IInternalModalAttrs {
  /** Discriminador desta verificação (verifiedAt). Persistido no
   *  preference `ramonVerifiedCelebrationShownAt`. */
  stamp: string;
}

/**
 * Modal de celebração mostrado uma única vez quando o usuário acaba de
 * ser verificado. Em `hide()` persiste o stamp da verificação atual
 * (`verifiedAt`) na preferência server-side
 * `ramonVerifiedCelebrationShownAt` — assim uma re-verificação no futuro
 * reabre o modal exatamente uma vez.
 */
export default class VerifiedCelebrationModal extends Modal<IVerifiedCelebrationModalAttrs> {
  protected persisted = false;

  className(): string {
    return "VerifiedRequestModal VerifiedCelebrationModal Modal--medium";
  }

  title(): Mithril.Children {
    return app.translator.trans(
      "ramon-verified.forum.verified_celebration.title",
    );
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body VerifiedRequestModal-body">
        <div className="VerifiedRequestModal-hero">
          <div className="VerifiedRequestModal-hero-icon" aria-hidden="true">
            <span className="VerifiedRequestModal-hero-iconBadge">
              {m.trust(getBadgeSvg())}
            </span>
          </div>
          <h2 className="VerifiedRequestModal-hero-title">
            {app.translator.trans(
              "ramon-verified.forum.verified_celebration.title",
            )}
          </h2>
          <p className="VerifiedRequestModal-hero-text">
            {app.translator.trans(
              "ramon-verified.forum.verified_celebration.body",
            )}
          </p>
        </div>

        <div className="VerifiedRequestModal-footer">
          <Button
            className="Button Button--primary"
            onclick={() => this.hide()}
          >
            {app.translator.trans(
              "ramon-verified.forum.verified_celebration.ok_button",
            )}
          </Button>
        </div>
      </div>
    );
  }

  hide(): void {
    this.persistShown();
    super.hide();
  }

  protected persistShown(): void {
    if (this.persisted) return;
    this.persisted = true;

    const user = app.session && app.session.user;
    if (!user || !user.savePreferences) return;

    try {
      user.savePreferences({
        ramonVerifiedCelebrationShownAt: this.attrs.stamp || "auto",
      });
    } catch {
      // Falha de rede deixa a flag não-persistida — modal reabre na
      // próxima sessão. Aceitável (UX silenciosa, não bloqueia).
    }
  }
}
