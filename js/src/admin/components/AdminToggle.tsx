import Component, { ComponentAttrs } from "flarum/common/Component";
import Switch from "flarum/common/components/Switch";
import type Mithril from "mithril";

import { settings, saveSetting, getBool } from "../utils/settings";

export interface IAdminToggleAttrs extends ComponentAttrs {
  settingKey: string;
  label: Mithril.Children;
  help?: Mithril.Children;
}

/**
 * A boolean settings toggle that auto-saves on change. Mutates the live
 * `app.data.settings` map so other components reading the same key reflect
 * the new value before the API roundtrip resolves.
 */
export default class AdminToggle extends Component<IAdminToggleAttrs> {
  view(): Mithril.Children {
    const { settingKey, label, help } = this.attrs;
    const value = getBool(settingKey);

    return (
      <div className="Form-group VerifiedAdmin-toggle">
        <Switch
          state={value}
          onchange={(checked: boolean) => {
            settings()[settingKey] = checked;
            m.redraw();
            saveSetting({ [settingKey]: checked ? "1" : "0" });
          }}
        >
          {label}
        </Switch>
        {help && <p className="helpText">{help}</p>}
      </div>
    );
  }
}
