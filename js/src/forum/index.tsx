import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import VerifiedBadge from '../common/components/VerifiedBadge';
import RequestVerificationModal from './components/RequestVerificationModal';
import getBadgeSvg, { getBadgeColor, getBadgeSize, DEFAULT_VERIFIED_SVG } from '../common/utils/getBadgeSvg';

// ─── Avocado theme integration helpers ────────────────────────────────────
//
// Avocado renders user pages and home cards through its own components, so
// we can't rely on the standard Flarum extension points alone.

type VnodeLike = Mithril.Vnode<any, any> & {
  attrs?: Record<string, any>;
  children?: any;
};

/**
 * Recursively find the first vnode in `node` whose attrs.className contains
 * the given class. Returns null if no match.
 */
function findVnodeByClass(node: unknown, className: string): VnodeLike | null {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findVnodeByClass(child, className);
      if (found) return found;
    }
    return null;
  }
  const v = node as VnodeLike;
  const cls = v.attrs && v.attrs.className;
  if (typeof cls === 'string' && cls.split(/\s+/).indexOf(className) !== -1) {
    return v;
  }
  if (v.children) return findVnodeByClass(v.children, className);
  return null;
}

/**
 * Build a verified-badge vnode for use inside an avocado vnode tree.
 *
 * Returns the VerifiedBadge component when available, or falls back to a
 * plain <span> rendered via inline SVG if the component class isn't
 * accessible from this code path.
 */
function makeVerifiedVnode(user: User | null | undefined, className: string): Mithril.Vnode | null {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  const Cls: any =
    (typeof VerifiedBadge === 'function' ? VerifiedBadge : null) ||
    (typeof flarum !== 'undefined' && flarum.reg
      ? flarum.reg.get('ramon-verified', 'common/components/VerifiedBadge')
      : null);

  if (typeof Cls === 'function') {
    try {
      return m(Cls, { user, className });
    } catch (e) {
      // fall through to inline span
    }
  }

  let svg = DEFAULT_VERIFIED_SVG;
  let color: string | null = null;
  let size = '1.2em';
  let tooltip = 'Verified';

  try {
    const s = getBadgeSvg();
    if (typeof s === 'string' && s) svg = s;
    const c = getBadgeColor();
    if (typeof c === 'string' && c) color = c;
    const z = getBadgeSize();
    if (typeof z === 'string' && z) size = z;
    if (typeof app !== 'undefined' && app.translator) {
      const t = extractText(app.translator.trans('ramon-verified.lib.tooltip'));
      if (t) tooltip = t;
    }
  } catch (e) {
    // defaults
  }

  const style: Record<string, string> = { '--verified-size': size };
  if (color) style.color = color;

  return m(
    'span',
    {
      className: ('VerifiedBadge ' + (className || '')).trim(),
      style: style,
      role: 'img',
      title: tooltip,
      'aria-label': tooltip,
    },
    m.trust(svg)
  );
}

// Helper used by the AvatarEditor overrides to short-circuit any upload /
// removal attempt when the user is verified and the admin has enabled the
// "lock avatar" setting. The backend EnforceAvatarLock listener is the
// actual security boundary; this is UX.
function isLockedAvatar(component: { attrs?: { user?: User } }): boolean {
  const user = component.attrs && component.attrs.user;
  return !!(user && user.isAvatarLocked && user.isAvatarLocked());
}

function showLockedAlert(): void {
  app.alerts.show(
    { type: 'error' },
    app.translator.trans('ramon-verified.forum.avatar.locked_alert')
  );
}

/**
 * Verified-user flow for changing the locked avatar:
 *  1. Confirm — make it explicit that the verification will be revoked.
 *  2. Self-revoke verification via DELETE /verified/users/{id}/verify.
 *  3. Push the new state into the local user model so the avatar editor
 *     unlocks immediately.
 */
function requestAvatarChange(user: User | null | undefined): void {
  if (!user || !user.id) return;

  const confirmText = extractText(
    app.translator.trans('ramon-verified.forum.avatar.request_change_confirm')
  );
  if (!window.confirm(confirmText)) return;

  app
    .request<{ data?: { attributes?: Record<string, unknown> } }>({
      method: 'DELETE',
      url: app.forum.attribute('apiUrl') + '/verified/users/' + user.id() + '/verify',
      body: {},
    })
    .then((res) => {
      if (res && res.data && res.data.attributes) {
        user.pushAttributes(res.data.attributes);
      } else {
        user.pushAttributes({ isVerified: false, verifiedAt: null });
      }
      user.pushAttributes({ isAvatarLocked: false });

      app.alerts.show(
        { type: 'success' },
        app.translator.trans('ramon-verified.forum.avatar.request_change_success')
      );
      m.redraw();
    })
    .catch(() => {
      app.alerts.show(
        { type: 'error' },
        app.translator.trans('ramon-verified.forum.avatar.request_change_failed')
      );
    });
}

export { default as extend } from './extend';

// Use a low priority (-100) so this initializer runs AFTER avocado's
// (priority 0). Avocado replaces UserPage.prototype.view with its own
// hero-rendering view; we need to wrap THAT view to inject our badge.
app.initializers.add('ramon-verified', () => {
  // ----- Profile / hover card: append badge inside the identity h1 -----
  //
  // The same UserCard component renders both the full profile hero
  // (`className="Hero UserHero"`) and the floating hover card you see when
  // mousing over a username in a discussion list (`className="UserCard--popover"`).
  // The hover card IS itself a popover, so nesting our own VerifiedPopover
  // inside it would stack two popovers — there we render the bare badge with
  // a simple title tooltip. On the profile hero we want the rich popover.
  extend('flarum/forum/components/UserCard', 'contentItems', function (this: any, items: any) {
    const user = this.attrs.user as User | undefined;
    if (!user || !user.isVerified || !user.isVerified()) return;

    if (!items.has('identity')) return;

    const identityItem = items.get('identity');
    if (!identityItem) return;

    const cardClass = String(this.attrs.className || '');
    const isHoverPopover = cardClass.indexOf('UserCard--popover') !== -1;

    const badge = (
      <VerifiedBadge user={user} className="VerifiedBadge--card" plain={isHoverPopover} />
    );

    items.setContent(
      'identity',
      <h1 className={(identityItem.attrs && identityItem.attrs.className) || 'UserCard-identity'}>
        {identityItem.children} {badge}
      </h1>
    );
  });

  // ----- Post header: badge as its own <li> in the Post-header <ul> -----
  extend('flarum/forum/components/CommentPost', 'headerItems', function (this: any, items: any) {
    const post = this.attrs.post;
    const user: User | undefined = post && post.user && post.user();
    if (!user || !user.isVerified || !user.isVerified()) return;

    items.add(
      'verified',
      <VerifiedBadge user={user} className="VerifiedBadge--post" />,
      95
    );
  });

  // ----- Settings page: request verification or show status -----
  extend('flarum/forum/components/SettingsPage', 'accountItems', function (this: any, items: any) {
    const user = app.session.user;
    if (!user) return;

    if (user.isVerified && user.isVerified()) {
      items.add(
        'verifiedStatus',
        <button type="button" className="Button VerifiedSettings-pill VerifiedSettings-pill--verified" disabled>
          <span className="Button-icon VerifiedSettings-pill-icon">
            <VerifiedBadge user={user} plain />
          </span>
          <span className="Button-label">
            {app.translator.trans('ramon-verified.forum.settings.verified_label')}
          </span>
        </button>,
        80
      );
      return;
    }

    if (user.hasPendingVerificationRequest && user.hasPendingVerificationRequest()) {
      items.add(
        'verifiedPending',
        <button type="button" className="Button VerifiedSettings-pill VerifiedSettings-pill--pending" disabled>
          <i className="icon fas fa-hourglass-half Button-icon" />
          <span className="Button-label">
            {app.translator.trans('ramon-verified.forum.settings.pending_label')}
          </span>
        </button>,
        80
      );
      return;
    }

    if (user.canRequestVerification && user.canRequestVerification()) {
      items.add(
        'verifiedRequest',
        <div className="Form-group">
          <Button className="Button" icon="fas fa-certificate" onclick={() => app.modal.show(RequestVerificationModal)}>
            {app.translator.trans('ramon-verified.forum.settings.request_button')}
          </Button>
        </div>,
        80
      );
    }
  });

  // ----- Admin: verify / revoke verification from the user dropdown -----
  extend(UserControls, 'moderationControls', function (items: any, user: User) {
    if (!app.forum.attribute('canVerifyUsers')) return;

    const apiUrl = app.forum.attribute('apiUrl');
    const isVerified = user.isVerified && user.isVerified();

    const performAction = (method: 'POST' | 'DELETE', promptKey: string, alertKey: string) => {
      const note = window.prompt(extractText(app.translator.trans('ramon-verified.forum.user_controls.' + promptKey)));
      if (note === null) return;

      app
        .request<{ data?: { attributes?: Record<string, unknown> } }>({
          method,
          url: apiUrl + '/verified/users/' + user.id() + '/verify',
          body: { adminNote: note || '' },
        })
        .then((res) => {
          if (res && res.data && res.data.attributes) {
            user.pushAttributes(res.data.attributes);
          } else {
            user.pushAttributes({
              isVerified: method === 'POST',
              verifiedAt: method === 'POST' ? new Date().toISOString() : null,
            });
          }
          app.alerts.show({ type: 'success' }, app.translator.trans('ramon-verified.forum.user_controls.' + alertKey));
          m.redraw();
        })
        .catch(() => m.redraw());
    };

    if (isVerified) {
      items.add(
        'verifiedRevoke',
        <Button icon="fas fa-ban" onclick={() => performAction('DELETE', 'revoke_prompt', 'revoke_success')}>
          {app.translator.trans('ramon-verified.forum.user_controls.revoke_button')}
        </Button>,
        50
      );
    } else {
      items.add(
        'verifiedVerify',
        <Button icon="fas fa-certificate" onclick={() => performAction('POST', 'verify_prompt', 'verify_success')}>
          {app.translator.trans('ramon-verified.forum.user_controls.verify_button')}
        </Button>,
        50
      );
    }
  });

  // ----- Avatar lock: replace upload buttons + intercept upload paths -----
  extend('flarum/forum/components/AvatarEditor', 'controlItems', function (this: any, items: any) {
    if (!isLockedAvatar(this)) return;

    if (items.has('upload')) items.remove('upload');
    if (items.has('remove')) items.remove('remove');

    const user = this.attrs.user as User | undefined;

    items.add(
      'verified-locked',
      <div className="AvatarEditor-lockedNotice">
        <i className="icon fas fa-lock" />
        <span className="AvatarEditor-lockedNotice-title">
          {app.translator.trans('ramon-verified.forum.avatar.locked_label')}
        </span>
        <span className="AvatarEditor-lockedNotice-text">
          {app.translator.trans('ramon-verified.forum.avatar.locked_help')}
        </span>
        <Button
          className="Button Button--primary AvatarEditor-lockedNotice-button"
          icon="fas fa-pen"
          onclick={(e: Event) => {
            if (e) { e.stopPropagation(); }
            requestAvatarChange(user);
          }}
        >
          {app.translator.trans('ramon-verified.forum.avatar.request_change_button')}
        </Button>
      </div>,
      100
    );
  });

  override('flarum/forum/components/AvatarEditor', 'quickUpload', function (this: any, original: any, ...args: unknown[]) {
    const e = args[0] as Event | undefined;
    if (isLockedAvatar(this)) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      showLockedAlert();
      return;
    }
    return original(e);
  });

  override('flarum/forum/components/AvatarEditor', 'openPicker', function (this: any, original: any) {
    if (isLockedAvatar(this)) {
      showLockedAlert();
      return;
    }
    return original();
  });

  override('flarum/forum/components/AvatarEditor', 'remove', function (this: any, original: any) {
    if (isLockedAvatar(this)) {
      showLockedAlert();
      return;
    }
    return original();
  });

  override('flarum/forum/components/AvatarEditor', 'dropUpload', function (this: any, original: any, ...args: unknown[]) {
    const e = args[0] as Event | undefined;
    if (isLockedAvatar(this)) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      showLockedAlert();
      return;
    }
    return original(e);
  });

  // ----- Notification grid: expose `userVerified` toggle in preferences -----
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (this: any, items: any) {
    items.add('userVerified', {
      name: 'userVerified',
      icon: 'fas fa-certificate',
      label: app.translator.trans('ramon-verified.forum.notifications.user_verified_preference'),
    });
  });

  // ----- Avocado theme integration -----
  installAvocadoIntegration();
}, -100);

/**
 * Hook into avocado's components to inject the verified badge.
 */
function installAvocadoIntegration(): void {
  // ── Profile hero: AvocadoUserPage-hero-name ───────────────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/UserProfilePage', (mod: any) => {
    if (!mod) return;
    const sample = mod.AvocadoUserPostsPage || mod.AvocadoUserDiscussionsPage
                || mod.AvocadoUserLikesPage  || mod.AvocadoUserMentionsPage;
    if (!sample || !sample.prototype) return;

    const baseProto = Object.getPrototypeOf(sample.prototype);
    if (!baseProto || typeof baseProto.view !== 'function') return;

    patchHeroView(baseProto);
  });

  override('flarum/forum/components/UserPage', 'view', function (this: any, original: Function, ...args: unknown[]) {
    const tree = original ? original.apply(this, args) : null;
    try {
      injectIntoHeroName(tree, this.user);
    } catch (e) { /* defensive */ }
    return tree;
  });

  // ── Thread cards on the home / tag / search pages ─────────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/ThreadCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode: any) => {
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return discussion && discussion.user && discussion.user();
    });
  });

  // ── PostCard (used in user profile / search results) ──────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/PostCard', (Component: any) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode: any) => {
      const post = vnode.attrs && vnode.attrs.post;
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return (post && post.user && post.user())
        || (discussion && discussion.user && discussion.user());
    });
  });
}

/**
 * Wrap a class prototype's `view` so that, after the original view returns
 * a vnode tree, we walk it looking for `.AvocadoUserPage-hero-name` and
 * append the verified badge into that h1.
 */
function patchHeroView(proto: any): void {
  const originalView = proto.view;
  proto.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      injectIntoHeroName(tree, this.user);
    } catch (e) {
      // Defensive — never crash the page.
    }
    return tree;
  };
}

/**
 * Walk the vnode tree, find the avocado hero h1, and append the badge into
 * its children.
 */
function injectIntoHeroName(tree: unknown, user: User | undefined | null): void {
  if (!tree) return;
  if (!user || !user.isVerified || !user.isVerified()) return;

  const heroName = findVnodeByClass(tree, 'AvocadoUserPage-hero-name');
  if (!heroName) return;

  const badge = makeVerifiedVnode(user, 'VerifiedBadge--card');
  if (!badge) return;

  const existing = heroName.children;
  const base = existing == null
    ? []
    : (Array.isArray(existing) ? existing.slice() : [existing]);
  heroName.children = [...base, badge];
}

/**
 * Wrap a thread/post card component's view to inject the verified badge
 * after its author link.
 */
function wrapCardView(Component: any, getUser: (vnode: any) => User | null | undefined): void {
  const originalView = Component.prototype.view;
  Component.prototype.view = function (this: any, ...args: unknown[]) {
    const tree = originalView.apply(this, args);
    try {
      const user = getUser(this);
      if (user && user.isVerified && user.isVerified()) {
        const meta =
          findVnodeByClass(tree, 'AvocadoHome-threadMeta') ||
          findVnodeByClass(tree, 'AvocadoSearch-threadMeta');

        if (meta && Array.isArray(meta.children)) {
          const authorIdx = meta.children.findIndex(
            (c: any) => c && c.attrs && typeof c.attrs.className === 'string' && (
              c.attrs.className.indexOf('AvocadoHome-threadAuthor') !== -1 ||
              c.attrs.className.indexOf('AvocadoSearch-threadAuthor') !== -1
            )
          );
          if (authorIdx !== -1) {
            const badge = makeVerifiedVnode(user, '');
            if (badge) {
              meta.children = [
                ...meta.children.slice(0, authorIdx + 1),
                badge,
                ...meta.children.slice(authorIdx + 1),
              ];
            }
          }
        }
      }
    } catch (e) {
      // Defensive — never crash card rendering.
    }
    return tree;
  };
}
