import app from 'flarum/common/app';
import trustedHtml from '../utils/trustedHtml';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import getBadgeSvg, { getBadgeSize } from '../utils/getBadgeSvg';
import secondaryName from '../utils/secondaryName';
import { resolveTierForUser, getTierColor, sanitiseDescription } from '../utils/tiers';

export interface VerifiedPopoverAttrs extends ComponentAttrs {
  user: User;
  size?: string;
}

export default class VerifiedPopover extends Component<VerifiedPopoverAttrs> {
  // O cartão só é montado no primeiro hover/foco, com m.render num nó próprio:
  // o CommentPost retém a subárvore do post e ignoraria um redraw do pai.
  private activated = false;
  private host: Element | null = null;
  private content: () => Mithril.Children = () => null;

  private activate = (e: Event & { redraw?: boolean }) => {
    e.redraw = false;
    if (this.activated || !this.host) return;
    this.activated = true;
    m.render(this.host, this.content());
  };

  onupdate(vnode: Mithril.VnodeDOM<VerifiedPopoverAttrs, this>) {
    super.onupdate(vnode);
    if (this.activated && this.host) m.render(this.host, this.content());
  }

  onremove(vnode: Mithril.VnodeDOM<VerifiedPopoverAttrs, this>) {
    super.onremove(vnode);
    if (this.host) m.render(this.host, null);
  }

  view(): Mithril.Children {
    const { user } = this.attrs;
    if (!user || !user.isVerified || !user.isVerified()) return null;

    const tier = resolveTierForUser(user);
    const color = getTierColor(tier);
    const size = this.attrs.size || getBadgeSize();

    const badgeStyle: Record<string, string> = { '--verified-size': size };
    if (color) badgeStyle.color = color;

    const verifiedAt = user.verifiedAt && user.verifiedAt();
    const ariaLabel = tier && tier.label ? tier.label : extractText(app.translator.trans('ramon-verified.lib.tooltip'));

    // A descrição já veio sanitizada, mas é sanitizada de novo aqui porque um
    // tier montado fora do normalise não pode levar HTML cru ao m.trust.
    const tierDescription = tier && tier.description ? tier.description : null;
    const headline: Mithril.Children = tierDescription
      ? trustedHtml(sanitiseDescription(tierDescription))
      : app.translator.trans('ramon-verified.lib.popover.headline');

    const learnMoreUrl = tier && tier.learnMoreUrl ? tier.learnMoreUrl : '';

    const tierClass = tier ? `VerifiedBadge--tier-${tier.id}` : '';

    // Sem --tier-color o CSS cai na cor primária do tema.
    const popoverStyle: Record<string, string> = {};
    if (color) popoverStyle['--tier-color'] = color;

    this.content = () => this.popover(user, tier, color, verifiedAt, headline, learnMoreUrl);

    return (
      <span
        className="VerifiedPopover-anchor"
        data-tier={tier ? tier.id : undefined}
        style={popoverStyle}
        onmouseenter={this.activate}
        onfocusin={this.activate}
      >
        <span className={'VerifiedBadge VerifiedBadge--inAnchor ' + tierClass} style={badgeStyle} role="img" aria-label={ariaLabel} tabIndex={0}>
          {trustedHtml(getBadgeSvg(tier))}
        </span>

        <span className="VerifiedPopover-host" oncreate={(v: Mithril.VnodeDOM) => (this.host = v.dom)} />
      </span>
    );
  }

  private popover(
    user: User,
    tier: ReturnType<typeof resolveTierForUser>,
    color: string | null,
    verifiedAt: Date | null | undefined,
    headline: Mithril.Children,
    learnMoreUrl: string
  ): Mithril.Children {
    return (
      <span className="VerifiedPopover" role="tooltip">
        <span className="VerifiedPopover-arrow" aria-hidden="true" />

        <span className="VerifiedPopover-header">
          <span className="VerifiedPopover-headerIcon">{trustedHtml(getBadgeSvg(tier))}</span>
          <span className="VerifiedPopover-headerText">
            {headline}
            {learnMoreUrl && (
              <>
                {' '}
                <a
                  className="VerifiedPopover-learnMore"
                  href={learnMoreUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  // As regras de `a` do core vencem por especificidade em alguns contextos.
                  style={color ? { color } : undefined}
                >
                  {app.translator.trans('ramon-verified.lib.popover.learn_more')}
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
              <span className="VerifiedPopover-displayName">{user.displayName()}</span>
              {secondaryName(user) && <span className="VerifiedPopover-username">{secondaryName(user)}</span>}
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
    );
  }
}
