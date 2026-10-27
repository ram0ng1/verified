import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import type Mithril from 'mithril';

let Panel: Mithril.ComponentTypes<any> | null = null;
let pending: Promise<void> | null = null;

// O painel só aparece na página desta extensão, então fica fora do admin.js
// que toda página do admin baixa.
export default class LazyVerifiedSettingsPanel extends Component {
  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);

    if (!Panel && !pending) {
      pending = import('./VerifiedSettingsPanel')
        .then((mod) => {
          Panel = mod.default;
          m.redraw();
        })
        .catch(() => {
          // Chunk falhou (deploy no meio, rede): a próxima visita tenta de novo.
          pending = null;
        });
    }
  }

  view() {
    return Panel ? m(Panel) : <LoadingIndicator />;
  }
}
