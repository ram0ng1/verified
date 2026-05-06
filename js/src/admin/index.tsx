import app from 'flarum/admin/app';
import { override } from 'flarum/common/extend';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import VerifiedSettingsPanel from './components/VerifiedSettingsPanel';

export { default as extend } from '../common/extend';

const EXT_ID = 'ramon-verified';

// Settings auto-save (avocado pattern) — hide the default submit button.
override(ExtensionPage.prototype, 'submitButton', function (this: any, original: () => unknown) {
  if (this.extension && this.extension.id === EXT_ID) return null;
  return original();
});

app.initializers.add(EXT_ID, () => {
  app.registry
    .for(EXT_ID)
    .registerSetting(() => <VerifiedSettingsPanel />, 100, 'ramon-verified.panel')
    .registerPermission(
      {
        icon: 'fas fa-certificate',
        label: app.translator.trans('ramon-verified.admin.permissions.request_label'),
        permission: 'verified.request',
      },
      'start'
    )
    .registerPermission(
      {
        icon: 'fas fa-user-check',
        label: app.translator.trans('ramon-verified.admin.permissions.verify_users_label'),
        permission: 'verified.verifyUsers',
      },
      'moderate'
    )
    .registerPermission(
      {
        icon: 'fas fa-certificate',
        label: app.translator.trans('ramon-verified.admin.permissions.auto_verified_label'),
        permission: 'verified.autoVerified',
      },
      'start'
    );
});
