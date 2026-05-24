import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Stream from "flarum/common/utils/Stream";
import extractText from "flarum/common/utils/extractText";
import Tooltip from "flarum/common/components/Tooltip";

import type ItemList from "flarum/common/utils/ItemList";
import type Mithril from "mithril";
import type MithrilStream from "mithril/stream";

import getBadgeSvg from "../common/utils/getBadgeSvg";

const SESSION_KEY = "ramonVerifiedSignupIntent";
const SIGNUP_MODULE = "flarum/forum/components/SignUpModal";

/**
 * Injeta um checkbox "Quero solicitar verificação" no formulário de
 * cadastro (`SignUpModal`). Quando marcado e enviado, persistimos a
 * intenção em `sessionStorage` antes da chamada de signup; o
 * `addSignupVerificationFlow` consome no próximo boot.
 *
 * **Por que `extend()` com string** em vez de `SignUpModal.prototype`:
 * `SignUpModal` é lazy-loaded em Flarum 2 — o core só importa quando o
 * modal abre. Acessar `.prototype` em tempo de boot quebra com
 * `undefined is not an object`. A forma com path resolve no momento
 * certo (quando o módulo de fato carrega).
 *
 * **`this: any`** nas callbacks: o overload `extend(string, …)` do core
 * não permite tipar `this` (T extends Record<string, any> conflita com
 * `T = string`). Mesmo padrão usado pelas outras extensões deste repo
 * (`addVerificationRequestButton`). A propriedade `requestVerificationAtSignup`
 * é anexada dinamicamente no `oninit`.
 *
 * **Gates de exibição** (ambos têm que estar true):
 * - `ramonVerifiedPromptOnRegister`: admin escolheu mostrar.
 * - `ramonVerifiedRequestsOpen`: admin não fechou solicitações.
 * Sem o segundo gate, o checkbox apareceria com requests fechados e
 * o POST falharia silenciosamente após o submit.
 */
export default function addSignupVerificationOption(): void {
  extend(SIGNUP_MODULE, "oninit", function (this: any) {
    this.requestVerificationAtSignup = Stream<boolean>(
      false,
    ) as MithrilStream<boolean>;
  });

  extend(
    SIGNUP_MODULE,
    "fields",
    function (this: any, items: ItemList<unknown>) {
      if (!app.forum.attribute("ramonVerifiedPromptOnRegister")) return;
      if (!app.forum.attribute("ramonVerifiedRequestsOpen")) return;

      const checked = !!(
        this.requestVerificationAtSignup && this.requestVerificationAtSignup()
      );
      const labelText = extractText(
        app.translator.trans(
          "ramon-verified.forum.signup_option.label",
        ) as Mithril.Children,
      );
      const helpText = extractText(
        app.translator.trans(
          "ramon-verified.forum.signup_option.help",
        ) as Mithril.Children,
      );

      items.add(
        "ramonVerifiedRequestOption",
        <div className="Form-group VerifiedSignupOption">
          <label className="VerifiedSignupOption-label">
            <input
              type="checkbox"
              className="VerifiedSignupOption-checkbox"
              checked={checked}
              disabled={this.loading}
              onchange={(e: Event) => {
                if (this.requestVerificationAtSignup) {
                  this.requestVerificationAtSignup(
                    (e.target as HTMLInputElement).checked,
                  );
                }
              }}
            />
            <span className="VerifiedSignupOption-labelText">
              {labelText}
              <span className="VerifiedSignupOption-badge" aria-hidden="true">
                {m.trust(getBadgeSvg())}
              </span>
              <Tooltip text={helpText} position="top">
                <button
                  type="button"
                  className="VerifiedSignupOption-info"
                  aria-label={helpText}
                  onclick={(e: Event) => {
                    e.preventDefault();
                    e.stopPropagation();
                  }}
                >
                  <i className="icon fas fa-circle-info" aria-hidden="true" />
                </button>
              </Tooltip>
            </span>
          </label>
        </div>,
        5,
      );
    },
  );

  // Captura a intenção antes do submit do core. O signup pode falhar
  // (validação 422); nesse caso o usuário fica na página e a flag
  // permanece — quando ele corrigir e submeter de novo com sucesso, é
  // usada. Se ele fechar a aba, sessionStorage some.
  extend(SIGNUP_MODULE, "onsubmit", function (this: any) {
    const wants = !!(
      this.requestVerificationAtSignup && this.requestVerificationAtSignup()
    );
    try {
      if (wants) sessionStorage.setItem(SESSION_KEY, "1");
      else sessionStorage.removeItem(SESSION_KEY);
    } catch {
      // sessionStorage indisponível (iframe sandbox etc.) — o usuário
      // não verá o modal de confirmação pós-signup, mas o cadastro
      // funciona.
    }
  });
}
