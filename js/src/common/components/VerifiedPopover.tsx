import app from "flarum/common/app";
import Component, { ComponentAttrs } from "flarum/common/Component";
import Avatar from "flarum/common/components/Avatar";
import humanTime from "flarum/common/utils/humanTime";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";
import type User from "flarum/common/models/User";
import getBadgeSvg, { getBadgeSize } from "../utils/getBadgeSvg";
import { resolveTierForUser, getTierColor, sanitiseDescription } from "../utils/tiers";

export interface VerifiedPopoverAttrs extends ComponentAttrs {
  user: User;
  size?: string;
}

/**
 * Rich popover for the verified badge — opens on hover/focus via CSS.
 *
 * The popover content is tier-aware: header label, description and the
 * "Learn more" link all come from the configured tier definition. The
 * anchor is the badge element itself, so the absolutely-positioned
 * popover panel can centre on it cleanly.
 */
export default class VerifiedPopover extends Component<VerifiedPopoverAttrs> {
  view(): Mithril.Children {
    const { user } = this.attrs;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const tier = resolveTierForUser(user);
    const color = getTierColor(tier);
    const size = this.attrs.size || getBadgeSize();

    const badgeStyle: Record<string, string> = { "--verified-size": size };
    if (color) badgeStyle.color = color;

    const verifiedAt = user.verifiedAt && user.verifiedAt();
    const ariaLabel =
      tier && tier.label
        ? tier.label
        : extractText(app.translator.trans("ramon-verified.lib.tooltip"));

    // The header sentence is the tier description (e.g. "Conta de
    // <strong>organização verificada</strong>."), allowed to carry sanitised
    // <strong>/<em> markup so admins keep the colored-bold visual treatment
    // the previous design had. When no description is configured for a tier,
    // we fall back to the universal "identidade verificada" translation so
    // the popover never renders empty.
    //
    // Defense-in-depth (§9.2): `tier.description` ALREADY went through
    // `sanitiseDescription` in `tiers.ts:normalise` AND server-side
    // `TierConfig::sanitiseDescription`. We re-run the JS sanitiser at the
    // render site so any future code path that builds a tier object outside
    // `normalise` (admin previews, test fixtures, hot-reloaded settings)
    // can't sneak raw HTML into `m.trust`.
    const tierDescription = tier && tier.description ? tier.description : null;
    const headline: Mithril.Children = tierDescription
      ? m.trust(sanitiseDescription(tierDescription))
      : app.translator.trans("ramon-verified.lib.popover.headline");

    const learnMoreUrl = tier && tier.learnMoreUrl ? tier.learnMoreUrl : "";

    const tierClass = tier ? `VerifiedBadge--tier-${tier.id}` : "";

    // Pass the resolved tier color down through a CSS variable so every
    // accent inside the popover (header icon, <strong> in the headline,
    // and the badge in the anchor) tints together. CSS falls back to the
    // theme primary when --tier-color isn't set (no tier configured / no
    // color on the tier).
    const popoverStyle: Record<string, string> = {};
    if (color) popoverStyle["--tier-color"] = color;

    return (
      <span
        className="VerifiedPopover-anchor"
        data-tier={tier ? tier.id : undefined}
        style={popoverStyle}
      >
        <span
          className={"VerifiedBadge VerifiedBadge--inAnchor " + tierClass}
          style={badgeStyle}
          role="img"
          aria-label={ariaLabel}
          tabIndex={0}
        >
          {m.trust(getBadgeSvg(tier))}
        </span>

        <span className="VerifiedPopover" role="tooltip">
          <span className="VerifiedPopover-arrow" aria-hidden="true" />

          <span className="VerifiedPopover-header">
            <span className="VerifiedPopover-headerIcon">
              {m.trust(getBadgeSvg(tier))}
            </span>
            <span className="VerifiedPopover-headerText">
              {headline}
              {learnMoreUrl && (
                <>
                  {" "}
                  <a
                    className="VerifiedPopover-learnMore"
                    href={learnMoreUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    // Inline color as a final tiebreaker against Flarum
                    // core's generic `a` rules which can win on specificity
                    // in some chrome contexts (post bodies, etc).
                    style={color ? { color } : undefined}
                  >
                    {app.translator.trans(
                      "ramon-verified.lib.popover.learn_more",
                    )}
                  </a>
                </>
              )}
            </span>
          </span>

          <span className="VerifiedPopover-body">
            <span className="VerifiedPopover-user">
              <span className="VerifiedPopover-avatar">
                <Avatar user={user} />
              </span>
              <span className="VerifiedPopover-userText">
                <span className="VerifiedPopover-username">
                  {user.username()}
                </span>
                <span className="VerifiedPopover-displayName">
                  {user.displayName()}
                </span>
              </span>
            </span>

            <span className="VerifiedPopover-meta">
              {verifiedAt
                ? app.translator.trans(
                    "ramon-verified.lib.popover.verified_on",
                    {
                      date: extractText(humanTime(verifiedAt)),
                    },
                  )
                : app.translator.trans(
                    "ramon-verified.lib.popover.verified_no_date",
                  )}
            </span>
          </span>
        </span>
      </span>
    );
  }
}
