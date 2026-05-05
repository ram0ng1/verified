import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import getBadgeSvg, { getBadgeColor, getBadgeSize } from '../utils/getBadgeSvg';
import VerifiedPopover from './VerifiedPopover';

/**
 * Verified badge — displayed beside a username when the user has been
 * verified by an admin.
 *
 * If the admin has enabled the rich tooltip (`ramon-verified.show_tooltip`,
 * default on), the badge is wrapped in a {@link VerifiedPopover} that opens
 * a GitHub-style detail card on hover. Otherwise, a bare badge is rendered
 * with no tooltip at all.
 */
export default class VerifiedBadge extends Component {
  view() {
    const user = this.attrs.user;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    // Skip the rich popover when the caller asks for a plain badge — used
    // inside the UserCard hover popup (which is already a popover itself,
    // so nesting another one looks "duplicated") and inside the admin
    // settings preview where the popover would obscure the rest of the UI.
    const showTooltip =
      app.forum.attribute('ramonVerifiedShowTooltip') !== false && !this.attrs.plain;

    if (showTooltip) {
      return <VerifiedPopover user={user} size={this.attrs.size} />;
    }

    const color = getBadgeColor();
    const size = this.attrs.size || getBadgeSize();
    const className = classList('VerifiedBadge', this.attrs.className);
    const tooltip = extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    const style = { '--verified-size': size };
    if (color) style.color = color;

    // When the rich popover is disabled, fall back to the browser's native
    // tooltip via the `title` attribute — a simple "Verified account" hover
    // hint with no JS / CSS overhead.
    return (
      <span
        className={className}
        style={style}
        role="img"
        aria-label={tooltip}
        title={tooltip}
      >
        {m.trust(getBadgeSvg())}
      </span>
    );
  }
}
