import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import UserControls from 'flarum/forum/utils/UserControls';
import extractText from 'flarum/common/utils/extractText';
import VerifiedBadge from '../common/components/VerifiedBadge';
import RequestVerificationModal from './components/RequestVerificationModal';
import getBadgeSvg, { getBadgeColor, getBadgeSize, DEFAULT_VERIFIED_SVG } from '../common/utils/getBadgeSvg';

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
  if (!parent || child == null) return;
  const existing = parent.children;
  if (existing == null) {
    parent.children = [child];
  } else if (Array.isArray(existing)) {
    parent.children = [...existing, child];
  } else {
    parent.children = [existing, child];
  }
}

/**
 * Build a verified-badge vnode for use inside an avocado vnode tree.
 *
 * Returns the VerifiedBadge component when available so the rich popover
 * setting is honoured everywhere, or falls back to a plain <span> rendered
 * via inline SVG if the component class isn't accessible from this code
 * path (defensive — keeps the page from crashing if a future build does
 * something unexpected with module resolution).
 */
function makeVerifiedVnode(user, className) {
  if (!user || !user.isVerified || !user.isVerified()) return null;

  // Resolve VerifiedBadge with two fallbacks so a transient undefined
  // import binding can't take down the whole page render.
  const Cls =
    (typeof VerifiedBadge === 'function' ? VerifiedBadge : null) ||
    (typeof flarum !== 'undefined' && flarum.reg
      ? flarum.reg.get('ramon-verified', 'common/components/VerifiedBadge')
      : null);

  if (typeof Cls === 'function') {
    // Use the proper component — it picks rich popover vs simple title
    // tooltip based on the `ramon-verified.show_tooltip` admin setting.
    try {
      return m(Cls, { user, className });
    } catch (e) {
      // fall through to inline span
    }
  }

  // Fallback: inline span. Loses the rich popover but keeps the badge
  // visible with a native browser tooltip. Only reached if Cls couldn't
  // be resolved.
  let svg = DEFAULT_VERIFIED_SVG;
  let color = null;
  let size = '1.2em';
  let tooltip = 'Verified';

  try {
    if (typeof getBadgeSvg === 'function') {
      const s = getBadgeSvg();
      if (typeof s === 'string' && s) svg = s;
    }
    if (typeof getBadgeColor === 'function') {
      const c = getBadgeColor();
      if (typeof c === 'string' && c) color = c;
    }
    if (typeof getBadgeSize === 'function') {
      const z = getBadgeSize();
      if (typeof z === 'string' && z) size = z;
    }
    if (typeof app !== 'undefined' && app.translator) {
      const t = extractText(app.translator.trans('ramon-verified.lib.tooltip'));
      if (t) tooltip = t;
    }
  } catch (e) {
    // defaults
  }

  const style = { '--verified-size': size };
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

/**
 * Verified-user flow for changing the locked avatar:
 *
 *  1. Confirm — make it explicit that the verification will be revoked.
 *  2. Self-revoke verification via DELETE /verified/users/{id}/verify
 *     (authorised because the actor is the target user).
 *  3. Push the new state into the local user model so the avatar editor
 *     unlocks immediately, and surface a follow-up alert reminding the
 *     user they can re-request verification afterwards.
 */
function requestAvatarChange(user) {
  if (!user || !user.id) return;

  const confirmText = extractText(
    app.translator.trans('ramon-verified.forum.avatar.request_change_confirm')
  );
  if (!window.confirm(confirmText)) return;

  app
    .request({
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
      // Locally clear the avatar-locked flag so the editor unlocks the
      // moment the dropdown re-renders, instead of waiting for the next
      // /api/users/X round-trip.
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

  // ----- Avatar lock: replace the avatar dropdown contents with an
  // explanatory notice + intercept every upload entry-point so a verified
  // user can't bypass the rule from the frontend. The backend
  // EnforceAvatarLock listener is the actual security boundary; this is UX.
  //
  // We intentionally don't add a visual lock overlay on the avatar — the
  // dropdown notice + the inline alert when an upload is attempted are
  // enough feedback, and the overlay just clutters the profile page.

  // Replace the upload/remove buttons with a single explanatory notice.
  extend('flarum/forum/components/AvatarEditor', 'controlItems', function (items) {
    if (!isLockedAvatar(this)) return;

    if (items.has('upload')) items.remove('upload');
    if (items.has('remove')) items.remove('remove');

    const user = this.attrs.user;

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
          onclick={(e) => {
            // Stop the click from bubbling up to the dropdown's outer
            // toggle (which would close the menu) before our confirm
            // dialog has a chance to run.
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

  // ----- Notification grid: expose `userVerified` toggle in preferences -----
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items) {
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
  // ── Profile hero: AvocadoUserPage-hero-name ───────────────────────────
  //
  // Avocado renders the user hero in TWO different places:
  //
  //  1. The named user pages (/u/{username}, /u/{username}/discussions,
  //     etc.) use `AvocadoUserPostsPage`, `AvocadoUserDiscussionsPage`,
  //     etc. — all extending an internal `AvocadoUserBase` with its own
  //     `view()`. That class shadows UserPage.view entirely.
  //
  //  2. The settings & security pages (/settings, /user-security) extend
  //     UserPage directly and pick up avocado's UserPage.view override.
  //
  // We need to patch BOTH so the badge shows in every place that renders
  // the avocado hero — profile tabs AND settings/security.

  // (1) Patch AvocadoUserBase via the prototype of any exported subclass.
  flarum.reg.onLoad('ramon-avocado', 'forum/components/UserProfilePage', (mod) => {
    if (!mod) return;
    const sample = mod.AvocadoUserPostsPage || mod.AvocadoUserDiscussionsPage
                || mod.AvocadoUserLikesPage  || mod.AvocadoUserMentionsPage;
    if (!sample || !sample.prototype) return;

    const baseProto = Object.getPrototypeOf(sample.prototype);
    if (!baseProto || typeof baseProto.view !== 'function') return;

    patchHeroView(baseProto);
  });

  // (2) Patch UserPage.view (which avocado overrode). With initializer
  // priority -100, our wrapper sits OUTSIDE avocado's wrapper, so we can
  // walk the rendered tree from avocado's hero markup. This covers
  // settings, security, and any other UserPage subclass that doesn't
  // shadow `view` itself.
  override('flarum/forum/components/UserPage', 'view', function (original, ...args) {
    const tree = original ? original.apply(this, args) : null;
    try {
      injectIntoHeroName(tree, this.user);
    } catch (e) { /* defensive */ }
    return tree;
  });

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
 * Wrap a class prototype's `view` so that, after the original view returns
 * a vnode tree, we walk it looking for `.AvocadoUserPage-hero-name` and
 * append the verified badge into that h1. Used by both the AvocadoUserBase
 * patch (for profile tabs) and the UserPage override (for settings/security).
 */
function patchHeroView(proto) {
  const originalView = proto.view;
  proto.view = function (...args) {
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
 * its children. Replaces children with a fresh array (not in-place mutation)
 * so Mithril's diff sees a clean new list each render.
 */
function injectIntoHeroName(tree, user) {
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
            const badge = makeVerifiedVnode(user, '');
            if (badge) {
              // Build a fresh children array rather than splice-mutating,
              // so Mithril's diff sees a clean list each render.
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
