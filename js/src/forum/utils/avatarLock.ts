import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type User from 'flarum/common/models/User';

export function isLockedAvatar(component: { attrs?: { user?: User } }): boolean {
  const user = component.attrs && component.attrs.user;
  return !!(user && user.isAvatarLocked && user.isAvatarLocked());
}

export function showLockedAlert(): void {
  app.alerts.show({ type: 'error' }, app.translator.trans('ramon-verified.forum.avatar.locked_alert'));
}

/**
 * Trocar o avatar travado exige abrir mão da verificação (autorrevogação).
 */
export function requestAvatarChange(user: User | null | undefined): void {
  if (!user || !user.id) return;

  const confirmText = extractText(app.translator.trans('ramon-verified.forum.avatar.request_change_confirm'));
  if (!window.confirm(confirmText)) return;

  app
    .request<{ data?: { attributes?: Record<string, unknown> } }>({
      method: 'DELETE',
      url: app.forum.attribute('apiUrl') + '/verified/users/' + user.id() + '/verify',
      body: {},
      errorHandler: () => {},
    })
    .then((res) => {
      if (res && res.data && res.data.attributes) {
        user.pushAttributes(res.data.attributes);
      } else {
        user.pushAttributes({ isVerified: false, verifiedAt: null });
      }
      user.pushAttributes({ isAvatarLocked: false });

      app.alerts.show({ type: 'success' }, app.translator.trans('ramon-verified.forum.avatar.request_change_success'));
      m.redraw();
    })
    .catch(() => {
      app.alerts.show({ type: 'error' }, app.translator.trans('ramon-verified.forum.avatar.request_change_failed'));
    });
}
