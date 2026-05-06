import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import getBadgeSvg, { getBadgeColor, getBadgeSize } from '../utils/getBadgeSvg';
import VerifiedPopover from './VerifiedPopover';

export interface VerifiedBadgeAttrs extends ComponentAttrs {
  user: User;
  size?: string;
  className?: string;
  /**
   * If true, render a bare badge without the rich popover (used when the
   * caller is itself inside a popover).
   */
  plain?: boolean;
}

/**
 * Verified badge — displayed beside a username when the user has been
 * verified by an admin.
 *
 * If the admin has enabled the rich tooltip (`ramon-verified.show_tooltip`,
 * default on), the badge is wrapped in a {@link VerifiedPopover} that opens
 * a GitHub-style detail card on hover. Otherwise, a bare badge is rendered
 * with no tooltip at all.
 */
export default class VerifiedBadge extends Component<VerifiedBadgeAttrs> {
  view(): Mithril.Children {
    const user = this.attrs.user;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const showTooltip =
      app.forum.attribute('ramonVerifiedShowTooltip') !== false && !this.attrs.plain;

    if (showTooltip) {
      return <VerifiedPopover user={user} size={this.attrs.size} />;
    }

    const color = getBadgeColor();
    const size = this.attrs.size || getBadgeSize();
    const className = classList('VerifiedBadge', this.attrs.className);
    const tooltip = extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    const style: Record<string, string> = { '--verified-size': size };
    if (color) style.color = color;

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
