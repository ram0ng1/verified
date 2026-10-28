import Component, { ComponentAttrs } from 'flarum/common/Component';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';

import { settings, saveSetting, getBool } from '../utils/settings';

export interface IAdminToggleAttrs extends ComponentAttrs {
  settingKey: string;
  label: Mithril.Children;
  help?: Mithril.Children;
}

// Altera app.data.settings na hora para quem lê a mesma chave não esperar a API.
export default class AdminToggle extends Component<IAdminToggleAttrs> {
  view(): Mithril.Children {
    const { settingKey, label, help } = this.attrs;
    const value = getBool(settingKey);

    return (
      <div className="Form-group VerifiedAdmin-toggle">
        <Switch
          state={value}
          onchange={(checked: boolean) => {
            const previous = settings()[settingKey];
            settings()[settingKey] = checked;
            m.redraw();
            // Se o save falhar o switch volta: o painel nunca mostra ligado
            // algo que o servidor não gravou.
            saveSetting({ [settingKey]: checked ? '1' : '0' }).catch(() => {
              settings()[settingKey] = previous;
              m.redraw();
            });
          }}
        >
          {label}
        </Switch>
        {help && <p className="helpText">{help}</p>}
      </div>
    );
  }
}
