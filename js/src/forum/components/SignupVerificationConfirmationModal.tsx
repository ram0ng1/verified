import app from "flarum/forum/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import Button from "flarum/common/components/Button";
import type Mithril from "mithril";

import getBadgeSvg from "../../common/utils/getBadgeSvg";

/**
 * Mostrado pós-signup quando o usuário marcou o checkbox de verificação no
 * cadastro **e o admin não exige documento**. Quando documento é exigido,
 * o fluxo abre o `RequestVerificationModal` (formulário completo) em vez
 * deste — esta modal só serve para o caso "POST vazio bem-sucedido".
 */
export default class SignupVerificationConfirmationModal extends Modal<IInternalModalAttrs> {
  className(): string {
    return "VerifiedRequestModal VerifiedSignupConfirmationModal Modal--medium";
  }

  title(): Mithril.Children {
    return app.translator.trans(
      "ramon-verified.forum.signup_confirmation.title",
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
              "ramon-verified.forum.signup_confirmation.title",
            )}
          </h2>

          <p className="VerifiedRequestModal-hero-text">
            {app.translator.trans(
              "ramon-verified.forum.signup_confirmation.body",
            )}
          </p>
        </div>

        <div className="VerifiedRequestModal-footer VerifiedSignupConfirmationModal-actions">
          <Button
            className="Button Button--primary"
            onclick={() => this.hide()}
          >
            {app.translator.trans(
              "ramon-verified.forum.signup_confirmation.ok_button",
            )}
          </Button>
        </div>
      </div>
    );
  }
}
