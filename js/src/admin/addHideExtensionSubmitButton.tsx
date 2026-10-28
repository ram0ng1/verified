import { override } from 'flarum/common/extend';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

const EXT_ID = 'ramon-verified';

// O painel salva a cada alteração, então o botão de salvar padrão sobra.
export default function addHideExtensionSubmitButton(): void {
  override(ExtensionPage.prototype, 'submitButton', function (this: any, original: () => unknown) {
    if (this.extension && this.extension.id === EXT_ID) return null;
    return original();
  });
}
