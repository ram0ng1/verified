import app from 'flarum/common/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import getBadgeSvg, { getBadgeColor, getBadgeSize } from '../utils/getBadgeSvg';

export interface VerifiedPopoverAttrs extends ComponentAttrs {
  user: User;
  size?: string;
}

/**
 * Rich popover for the verified badge — opens on hover/focus via CSS.
 *
 * The anchor is the badge element itself, so the absolutely-positioned
 * popover panel can centre on it cleanly with `left: 50%; translateX(-50%)`.
 */
export default class VerifiedPopover extends Component<VerifiedPopoverAttrs> {
  view(): Mithril.Children {
    const { user } = this.attrs;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const color = getBadgeColor();
    const size = this.attrs.size || getBadgeSize();
    const badgeStyle: Record<string, string> = { '--verified-size': size };
    if (color) badgeStyle.color = color;

    const verifiedAt = user.verifiedAt && user.verifiedAt();
    const ariaLabel = extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    return (
      <span className="VerifiedPopover-anchor">
        <span
          className="VerifiedBadge VerifiedBadge--inAnchor"
          style={badgeStyle}
          role="img"
          aria-label={ariaLabel}
          tabIndex={0}
        >
          {m.trust(getBadgeSvg())}
        </span>

        <span className="VerifiedPopover" role="tooltip">
          <span className="VerifiedPopover-arrow" aria-hidden="true" />

          <span className="VerifiedPopover-header">
            <span className="VerifiedPopover-headerIcon" style={color ? { color } : undefined}>
              {m.trust(getBadgeSvg())}
            </span>
            <span className="VerifiedPopover-headerText">
              {app.translator.trans('ramon-verified.lib.popover.headline')}
            </span>
          </span>

          <span className="VerifiedPopover-body">
            <span className="VerifiedPopover-user">
              <span className="VerifiedPopover-avatar">
                <Avatar user={user} />
              </span>
              <span className="VerifiedPopover-userText">
                <span className="VerifiedPopover-username">{user.username()}</span>
                <span className="VerifiedPopover-displayName">{user.displayName()}</span>
              </span>
            </span>

            <span className="VerifiedPopover-meta">
              {verifiedAt
                ? app.translator.trans('ramon-verified.lib.popover.verified_on', {
                    date: extractText(humanTime(verifiedAt)),
                  })
                : app.translator.trans('ramon-verified.lib.popover.verified_no_date')}
            </span>
          </span>
        </span>
      </span>
    );
  }
}
