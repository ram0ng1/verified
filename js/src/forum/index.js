import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';
import extractText from 'flarum/common/utils/extractText';
import VerifiedBadge from '../common/components/VerifiedBadge';
import RequestVerificationModal from './components/RequestVerificationModal';

// ─── Avocado theme integration helpers ────────────────────────────────────
//
// Avocado renders user pages and home cards through its own components, so
// we can't rely on the standard Flarum extension points alone. We hook into
// avocado's components via flarum.reg.onLoad (which only fires when the
// extension is actually loaded) and walk the rendered vnode tree to inject
// the verified badge into the right element.

/**
 * Recursively find the first vnode in `node` whose attrs.className contains
 * the given class. Returns null if no match.
 */
function findVnodeByClass(node, className) {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findVnodeByClass(child, className);
      if (found) return found;
    }
    return null;
  }
  const cls = node.attrs && node.attrs.className;
  if (typeof cls === 'string' && cls.split(/\s+/).indexOf(className) !== -1) {
    return node;
  }
  if (node.children) return findVnodeByClass(node.children, className);
  return null;
}

/**
 * Append a child vnode to a vnode's children, preserving the original
 * child shape (single child vs. array).
 */
function appendVnodeChild(parent, child) {
  if (!parent) return;
  const existing = parent.children;
  if (existing == null) {
    parent.children = [child];
  } else if (Array.isArray(existing)) {
    parent.children = [...existing, child];
  } else {
    parent.children = [existing, child];
  }
}

// Helper used by the AvatarEditor overrides to short-circuit any upload /
// removal attempt when the user is verified and the admin has enabled the
// "lock avatar" setting. Mirrors the backend EnforceAvatarLock listener so
// the user gets immediate feedback instead of a 422 from the API.
function isLockedAvatar(component) {
  const user = component.attrs && component.attrs.user;
  return user && user.isAvatarLocked && user.isAvatarLocked();
}

function showLockedAlert() {
  app.alerts.show(
    { type: 'error' },
    app.translator.trans('ramon-verified.forum.avatar.locked_alert')
  );
}

export { default as extend } from '../common/extend';

// Use a low priority (-100) so this initializer runs AFTER avocado's
// (priority 0). Avocado replaces UserPage.prototype.view with its own
// hero-rendering view; we need to wrap THAT view to inject our badge.
app.initializers.add('ramon-verified', () => {
  // ----- Profile / hover card: append badge inside the identity h1 -----
  extend('flarum/forum/components/UserCard', 'contentItems', function (items) {
    const user = this.attrs.user;
    if (!user || !user.isVerified || !user.isVerified()) return;

    if (!items.has('identity')) return;

    const identityItem = items.get('identity');
    if (!identityItem) return;

    // The user-card popup IS itself a hover popover, so render the badge
    // without its own rich tooltip — otherwise both popovers stack and
    // look duplicated.
    const badge = <VerifiedBadge user={user} className="VerifiedBadge--card" plain />;

    items.setContent(
      'identity',
      <h1 className={(identityItem.attrs && identityItem.attrs.className) || 'UserCard-identity'}>
        {identityItem.children} {badge}
      </h1>
    );
  });

  // ----- Post header: badge as its own <li> in the Post-header <ul> -----
  // Rendering the badge at the CommentPost.headerItems level (instead of
  // inside .PostUser) puts it in the top-level header list, sibling to
  // .item-user and .item-meta. That way it inherits the same
  // `margin-right: 10px` gap Flarum uses between every header item — the
  // badge ends up perfectly aligned with both the username and the
  // relative-time meta button.
  extend('flarum/forum/components/CommentPost', 'headerItems', function (items) {
    const post = this.attrs.post;
    const user = post && post.user && post.user();
    if (!user || !user.isVerified || !user.isVerified()) return;

    // user item is at priority 100, meta at 0 (default); 95 places the badge
    // right after the username and before the timestamp.
    items.add(
      'verified',
      <VerifiedBadge user={user} className="VerifiedBadge--post" />,
      95
    );
  });

  // ----- Settings page: request verification or show status -----
  extend('flarum/forum/components/SettingsPage', 'accountItems', function (items) {
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
  extend(UserControls, 'moderationControls', function (items, user) {
    if (!app.forum.attribute('canVerifyUsers')) return;

    const apiUrl = app.forum.attribute('apiUrl');
    const isVerified = user.isVerified && user.isVerified();

    const performAction = (method, promptKey, alertKey) => {
      const note = window.prompt(extractText(app.translator.trans('ramon-verified.forum.user_controls.' + promptKey)));
      if (note === null) return;

      app
        .request({
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

  // ----- Avatar lock: visual lock overlay + replace the dropdown contents
  // with an explanatory notice + intercept every upload entry-point so a
  // verified user can't bypass the rule from the frontend. The backend
  // EnforceAvatarLock listener is the actual security boundary; this is UX.

  extend('flarum/forum/components/AvatarEditor', ['oncreate', 'onupdate'], function (_, vnode) {
    const dom = vnode && vnode.dom;
    if (!dom || !dom.classList) return;

    if (isLockedAvatar(this)) {
      dom.classList.add('AvatarEditor--locked');
    } else {
      dom.classList.remove('AvatarEditor--locked');
    }
  });

  // Replace the upload/remove buttons with a single explanatory notice.
  extend('flarum/forum/components/AvatarEditor', 'controlItems', function (items) {
    if (!isLockedAvatar(this)) return;

    if (items.has('upload')) items.remove('upload');
    if (items.has('remove')) items.remove('remove');

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
      </div>,
      100
    );
  });

  // The avatar editor exposes four upload entry-points. Block them all when
  // locked so no path can bypass the UI message.
  override('flarum/forum/components/AvatarEditor', 'quickUpload', function (original, e) {
    if (isLockedAvatar(this)) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      showLockedAlert();
      return;
    }
    return original(e);
  });

  override('flarum/forum/components/AvatarEditor', 'openPicker', function (original) {
    if (isLockedAvatar(this)) {
      showLockedAlert();
      return;
    }
    return original();
  });

  override('flarum/forum/components/AvatarEditor', 'remove', function (original) {
    if (isLockedAvatar(this)) {
      showLockedAlert();
      return;
    }
    return original();
  });

  override('flarum/forum/components/AvatarEditor', 'dropUpload', function (original, e) {
    if (isLockedAvatar(this)) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      showLockedAlert();
      return;
    }
    return original(e);
  });

  // ----- Avocado theme integration -----
  installAvocadoIntegration();
}, -100);

/**
 * Hook into avocado's components to inject the verified badge.
 *
 * Two strategies:
 *  - User profile hero: avocado replaces UserPage.prototype.view in its own
 *    initializer. We override UserPage.view ourselves with a LATER priority
 *    so our wrapper sits on top of avocado's. We then walk avocado's
 *    rendered vnode tree to find `.AvocadoUserPage-hero-name` and append
 *    the badge.
 *  - Thread / post cards: avocado registers ThreadCard and PostCard as
 *    component classes via the auto-export loader. We extend each class's
 *    `view` method via `flarum.reg.onLoad`, which only fires when avocado
 *    is actually loaded — keeping the verified extension fully working on
 *    forums without avocado.
 */
function installAvocadoIntegration() {
  // `flarum.extensions` is populated on both forum and admin with the
  // currently-loaded extension bundles. `app.data.extensions` is admin-only,
  // so we'd never hit this branch on the forum side without the right check.
  const avocadoEnabled =
    typeof flarum !== 'undefined' &&
    flarum.extensions &&
    'ramon-avocado' in flarum.extensions;

  // ── Profile hero: AvocadoUserPage-hero-name ───────────────────────────
  // The verified initializer runs with priority -100, so by the time we
  // patch UserPage.prototype.view here, avocado has already installed its
  // own override (priority 0). Our wrapper sits OUTSIDE avocado's, calls
  // it, walks the resulting tree, and injects the badge into the hero h1.
  if (avocadoEnabled) {
    override('flarum/forum/components/UserPage', 'view', function (original, ...args) {
      const tree = original ? original.apply(this, args) : null;
      try {
        const user = this.user;
        if (user && user.isVerified && user.isVerified() && tree) {
          const heroName = findVnodeByClass(tree, 'AvocadoUserPage-hero-name');
          if (heroName) {
            appendVnodeChild(heroName, ' ');
            appendVnodeChild(
              heroName,
              m(VerifiedBadge, { user, className: 'VerifiedBadge--card' })
            );
          }
        }
      } catch (e) {
        // Defensive — never crash the page.
      }
      return tree;
    });
  }

  // ── Thread cards on the home / tag / search pages ─────────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/ThreadCard', (Component) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode) => {
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return discussion && discussion.user && discussion.user();
    });
  });

  // ── PostCard (used in user profile / search results) ──────────────────
  flarum.reg.onLoad('ramon-avocado', 'forum/components/shared/PostCard', (Component) => {
    if (!Component || !Component.prototype) return;
    wrapCardView(Component, (vnode) => {
      const post = vnode.attrs && vnode.attrs.post;
      const discussion = vnode.attrs && vnode.attrs.discussion;
      return (post && post.user && post.user())
        || (discussion && discussion.user && discussion.user());
    });
  });
}

/**
 * Wrap a thread/post card component's view to inject the verified badge
 * after its author link. Looks for both `AvocadoHome-threadMeta` and
 * `AvocadoSearch-threadMeta`, and the matching `…-threadAuthor` link inside.
 */
function wrapCardView(Component, getUser) {
  const originalView = Component.prototype.view;
  Component.prototype.view = function (...args) {
    const tree = originalView.apply(this, args);
    try {
      const user = getUser(this);
      if (user && user.isVerified && user.isVerified()) {
        // Avocado uses one of these two prefixes depending on whether the
        // card is rendered on the home or in the search results.
        const meta =
          findVnodeByClass(tree, 'AvocadoHome-threadMeta') ||
          findVnodeByClass(tree, 'AvocadoSearch-threadMeta');

        if (meta && Array.isArray(meta.children)) {
          const authorIdx = meta.children.findIndex(
            (c) => c && c.attrs && typeof c.attrs.className === 'string' && (
              c.attrs.className.indexOf('AvocadoHome-threadAuthor') !== -1 ||
              c.attrs.className.indexOf('AvocadoSearch-threadAuthor') !== -1
            )
          );
          if (authorIdx !== -1) {
            meta.children.splice(authorIdx + 1, 0, m(VerifiedBadge, { user }));
          }
        }
      }
    } catch (e) {
      // Defensive — never crash card rendering.
    }
    return tree;
  };
}
