import app from 'flarum/common/app';
import trustedHtml from '../utils/trustedHtml';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import getBadgeSvg, { getBadgeSize } from '../utils/getBadgeSvg';
import { resolveTierForUser, getTierColor } from '../utils/tiers';
import VerifiedPopover from './VerifiedPopover';

export interface VerifiedBadgeAttrs extends ComponentAttrs {
  user: User;
  size?: string;
  className?: string;
  // Sem popover, para quando o badge já está dentro de um.
  plain?: boolean;
}

export default class VerifiedBadge extends Component<VerifiedBadgeAttrs> {
  view(): Mithril.Children {
    const user = this.attrs.user;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const tier = resolveTierForUser(user);

    const showTooltip = app.forum.attribute('ramonVerifiedShowTooltip') !== false && !this.attrs.plain;

    if (showTooltip) {
      return <VerifiedPopover user={user} size={this.attrs.size} />;
    }

    const color = getTierColor(tier);
    const size = this.attrs.size || getBadgeSize();
    const tierClass = tier ? `VerifiedBadge--tier-${tier.id}` : '';
    const className = classList('VerifiedBadge', tierClass, this.attrs.className);

    const tooltip = tier && tier.label ? tier.label : extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    const style: Record<string, string> = { '--verified-size': size };
    if (color) style.color = color;

    return (
      <span className={className} style={style} role="img" aria-label={tooltip} title={tooltip} data-tier={tier ? tier.id : undefined}>
        {trustedHtml(getBadgeSvg(tier))}
      </span>
    );
  }
}
