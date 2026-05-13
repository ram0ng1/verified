import app from "flarum/common/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import classList from "flarum/common/utils/classList";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import getBadgeSvg, { getBadgeSize } from "../utils/getBadgeSvg";
import { resolveTierForUser, getTierColor } from "../utils/tiers";
import VerifiedPopover from "./VerifiedPopover";

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
 * with a simple title tooltip.
 *
 * The tier resolved for the user picks the badge color and the popover label
 * / description / "Learn more" link.
 */
export default class VerifiedBadge extends Component<VerifiedBadgeAttrs> {
  view(): Mithril.Children {
    const user = this.attrs.user;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const tier = resolveTierForUser(user);

    const showTooltip =
      app.forum.attribute("ramonVerifiedShowTooltip") !== false &&
      !this.attrs.plain;

    if (showTooltip) {
      return <VerifiedPopover user={user} size={this.attrs.size} />;
    }

    const color = getTierColor(tier);
    const size = this.attrs.size || getBadgeSize();
    const tierClass = tier ? `VerifiedBadge--tier-${tier.id}` : "";
    const className = classList(
      "VerifiedBadge",
      tierClass,
      this.attrs.className
    );

    const tooltip =
      tier && tier.label
        ? tier.label
        : extractText(app.translator.trans("ramon-verified.lib.tooltip"));

    const style: Record<string, string> = { "--verified-size": size };
    if (color) style.color = color;

    return (
      <span
        className={className}
        style={style}
        role="img"
        aria-label={tooltip}
        title={tooltip}
        data-tier={tier ? tier.id : undefined}
      >
        {m.trust(getBadgeSvg())}
      </span>
    );
  }
}
