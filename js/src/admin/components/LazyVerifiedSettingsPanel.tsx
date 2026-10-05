import Component from "flarum/common/Component";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";

import type Mithril from "mithril";

let Panel: Mithril.ComponentTypes<any> | null = null;
let pending: Promise<void> | null = null;

/**
 * Carrega o painel de configurações sob demanda. O painel só aparece na
 * página desta extensão, então fica fora do `admin.js` que todo acesso ao
 * admin baixa; a classe resolvida é reaproveitada nas visitas seguintes.
 */
export default class LazyVerifiedSettingsPanel extends Component {
  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);

    if (!Panel && !pending) {
      pending = import("./VerifiedSettingsPanel").then((mod) => {
        Panel = mod.default;
        m.redraw();
      });
    }
  }

  view() {
    return Panel ? m(Panel) : <LoadingIndicator />;
  }
}
