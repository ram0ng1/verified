import { override } from 'flarum/common/extend';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

const EXT_ID = 'ramon-verified';

/**
 * Hide the default submit button on this extension's settings page — the
 * panel auto-saves on every change (avocado pattern).
 */
export default function addHideExtensionSubmitButton(): void {
  override(ExtensionPage.prototype, 'submitButton', function (this: any, original: () => unknown) {
    if (this.extension && this.extension.id === EXT_ID) return null;
    return original();
  });
}
