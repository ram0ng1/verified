import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';

import type VerificationRequest from '../../common/models/VerificationRequest';
import VerificationRequestsState, { RequestAction, RequestTab } from '../states/VerificationRequestsState';
import ApprovedUsersState, { APPROVED_PAGE_SIZE, ApprovedRequestSummary, ApprovedUserRow } from '../states/ApprovedUsersState';
import DocumentPreviewModal from './DocumentPreviewModal';

const TABS: RequestTab[] = ['pending', 'approved', 'rejected'];

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.requests.${key}`, params ?? {});

export default class VerificationRequestsSection extends Component<ComponentAttrs> {
  protected tab: RequestTab = 'pending';
  protected requests!: VerificationRequestsState;
  protected approved!: ApprovedUsersState;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.requests = new VerificationRequestsState();
    this.approved = new ApprovedUsersState();
    this.requests.load();
    this.approved.load();
  }

  view(): Mithril.Children {
    const counts = {
      pending: this.requests.pendingCount(),
      approved: this.approved.total,
      rejected: this.requests.rejectedCount(),
    };

    return (
      <div className="VerifiedRequests">
        <header className="VerifiedAdmin-cardHeader">
          <h3>{trans('title')}</h3>
          <p className="helpText">{trans('description')}</p>
        </header>

        <nav className="VerifiedRequests-tabs" role="tablist">
          {TABS.map((status) => (
            <button
              type="button"
              className={'VerifiedRequests-tab' + (this.tab === status ? ' is-active' : '')}
              onclick={() => this.switchTab(status)}
              role="tab"
              aria-selected={this.tab === status ? 'true' : 'false'}
            >
              <span>{trans('tab_' + status)}</span>
              <span className="VerifiedRequests-count">{counts[status] ?? 0}</span>
            </button>
          ))}
        </nav>

        <div className="VerifiedRequests-body">
          {this.tab === 'approved' ? this.renderApprovedTab() : this.renderRequestTab()}
        </div>
      </div>
    );
  }

  renderRequestTab(): Mithril.Children {
    if (this.requests.loading) {
      return (
        <div className="VerifiedRequests-empty">
          <LoadingIndicator />
        </div>
      );
    }

    const list = this.requests.filteredRequests(this.tab);
    if (list.length === 0) {
      return (
        <div className="VerifiedRequests-empty">
          <i className="icon fas fa-inbox" />
          <span>{trans('empty_' + this.tab)}</span>
        </div>
      );
    }

    return <ul className="VerifiedRequests-list">{list.map((r) => this.renderItem(r))}</ul>;
  }

  // ── Approved tab ─────────────────────────────────────────────────────────

  renderApprovedTab(): Mithril.Children {
    const { loading, rows, total, offset, query, tiers, tierFilter } = this.approved;
    const placeholder = extractText(trans('search_placeholder'));

    return [
      <div className="VerifiedRequests-toolbar">
        <div className="VerifiedRequests-search">
          <i className="icon fas fa-search VerifiedRequests-search-icon" aria-hidden="true" />
          <input
            type="search"
            className="FormControl VerifiedRequests-search-input"
            placeholder={placeholder}
            value={query}
            oninput={(e: Event) => this.approved.setSearch((e.target as HTMLInputElement).value)}
          />
          {query ? (
            <button
              type="button"
              className="VerifiedRequests-search-clear"
              onclick={() => this.approved.setSearch('')}
              aria-label={extractText(trans('search_clear'))}
            >
              <i className="icon fas fa-times" />
            </button>
          ) : null}
        </div>
      </div>,

      tiers.length > 0 ? (
        <div className="VerifiedRequests-tierFilter" role="tablist" aria-label={extractText(trans('tier_filter_aria'))}>
          <button
            type="button"
            role="tab"
            aria-selected={tierFilter === ''}
            className={'VerifiedRequests-tierChip' + (tierFilter === '' ? ' is-active' : '')}
            onclick={() => this.approved.setTierFilter('')}
          >
            <i className="icon fas fa-globe" aria-hidden="true" />
            <span>{trans('tier_filter_all')}</span>
          </button>
          {tiers.map((t) => {
            const active = tierFilter === t.id;
            const swatch = /^#[0-9a-f]{3,8}$/i.test(t.color) ? t.color : null;
            return (
              <button
                type="button"
                role="tab"
                aria-selected={active}
                key={t.id}
                className={'VerifiedRequests-tierChip' + (active ? ' is-active' : '')}
                style={swatch ? { '--tier-color': swatch } as Record<string, string> : undefined}
                onclick={() => this.approved.setTierFilter(t.id)}
              >
                <span className="VerifiedRequests-tierChipDot" aria-hidden="true" />
                <span>{t.label}</span>
              </button>
            );
          })}
        </div>
      ) : null,

      loading && rows.length === 0 ? (
        <div className="VerifiedRequests-empty">
          <LoadingIndicator />
        </div>
      ) : rows.length === 0 ? (
        <div className="VerifiedRequests-empty">
          <i className="icon fas fa-inbox" />
          <span>
            {query
              ? trans('empty_search', { query })
              : trans('empty_approved')}
          </span>
        </div>
      ) : (
        <ul className={'VerifiedRequests-list' + (loading ? ' is-refreshing' : '')}>
          {rows.map((row) => this.renderApprovedItem(row))}
        </ul>
      ),

      total > APPROVED_PAGE_SIZE ? this.renderPagination(offset, total) : null,
    ];
  }

  renderApprovedItem(row: ApprovedUserRow): Mithril.Children {
    const busy = !!this.approved.busy['user-' + row.id];
    const isGroupOnly = row.source === 'group';
    const tier = this.approved.findTier(row.verifiedTier);
    const tierSwatch = tier && /^#[0-9a-f]{3,8}$/i.test(tier.color) ? tier.color : null;

    return (
      <li className={'VerifiedRequest' + (isGroupOnly ? ' VerifiedRequest--auto' : '')} key={'user-' + row.id}>
        <div className="VerifiedRequest-main">
          <div className="VerifiedRequest-user">
            <span className="username">{row.displayName || row.username}</span>
            {tier ? (
              <span
                className="VerifiedRequest-tierChip"
                style={tierSwatch ? { '--tier-color': tierSwatch } as Record<string, string> : undefined}
                title={tier.label}
              >
                <span className="VerifiedRequest-tierChipDot" aria-hidden="true" />
                {tier.label}
              </span>
            ) : null}
            {isGroupOnly ? (
              <span className="VerifiedRequest-status VerifiedRequest-status--auto">
                {trans('status_auto')}
              </span>
            ) : (
              <span className="VerifiedRequest-status VerifiedRequest-status--approved">
                {trans('status_approved')}
              </span>
            )}
          </div>

          {isGroupOnly ? (
            <div className="VerifiedRequest-autoNotice">
              <i className="icon fas fa-users" aria-hidden="true" />
              <span>
                {row.autoVerifiedGroups && row.autoVerifiedGroups.length
                  ? trans('auto_via_groups', {
                      groups: row.autoVerifiedGroups.map((g) => g.name).join(', '),
                    })
                  : trans('auto_via_group_unknown')}
              </span>
            </div>
          ) : row.request ? this.renderRequestMeta(row.request) : null}
        </div>

        <div className="VerifiedRequest-actions">
          {isGroupOnly ? (
            <span className="VerifiedRequest-autoHint helpText">{trans('auto_actions_hint')}</span>
          ) : (
            <Button
              className="Button Button--danger"
              loading={busy}
              onclick={() => this.revokeApprovedUser(row)}
            >
              <i className="icon fas fa-ban" /> {trans('revoke_button')}
            </Button>
          )}
        </div>
      </li>
    );
  }

  renderRequestMeta(req: ApprovedRequestSummary): Mithril.Children {
    const docPath = req.documentPath;
    const docUrl = docPath ? app.forum.attribute('apiUrl') + '/verified/documents/' + req.id : null;
    const created = req.createdAt ? new Date(req.createdAt) : null;
    const handled = req.handledAt ? new Date(req.handledAt) : null;
    const handlerName = req.handler ? (req.handler.displayName || req.handler.username) : null;

    return [
      <div className="VerifiedRequest-meta helpText">
        <span title={created ? created.toString() : ''}>{humanTime(created)}</span>
        {handlerName && handled ? (
          <span>
            {' · '}
            {trans('handled_by', {
              handlerName,
              date: extractText(humanTime(handled)),
            })}
          </span>
        ) : null}
      </div>,

      req.reason ? <blockquote className="VerifiedRequest-reason">{req.reason}</blockquote> : null,

      docPath && docUrl ? (
        <div className="VerifiedRequest-document">
          <i className="icon fas fa-id-card" />
          <span className="VerifiedRequest-docType">{this.formatDocType(req.documentType)}</span>
          <button
            type="button"
            className="VerifiedRequest-docLink"
            onclick={() => app.modal.show(DocumentPreviewModal, {
              url: docUrl,
              filename: docPath,
              typeLabel: this.formatDocType(req.documentType),
            })}
          >
            <i className="icon fas fa-eye" />
            {trans('view_document')}
          </button>
        </div>
      ) : null,

      req.adminNote ? (
        <div className="VerifiedRequest-note">
          <strong>{trans('admin_note_label')}:</strong> {req.adminNote}
        </div>
      ) : null,
    ];
  }

  renderPagination(offset: number, total: number): Mithril.Children {
    const page = Math.floor(offset / APPROVED_PAGE_SIZE) + 1;
    const lastPage = Math.max(1, Math.ceil(total / APPROVED_PAGE_SIZE));
    const canPrev = offset > 0;
    const canNext = offset + APPROVED_PAGE_SIZE < total;

    return (
      <div className="VerifiedRequests-pagination">
        <button
          type="button"
          className="Button Button--text VerifiedRequests-pagination-btn"
          disabled={!canPrev}
          onclick={() => this.approved.goToPage(offset - APPROVED_PAGE_SIZE)}
        >
          <i className="icon fas fa-chevron-left" />
          {trans('pagination_prev')}
        </button>
        <span className="VerifiedRequests-pagination-info">
          {trans('pagination_info', { page, lastPage, total })}
        </span>
        <button
          type="button"
          className="Button Button--text VerifiedRequests-pagination-btn"
          disabled={!canNext}
          onclick={() => this.approved.goToPage(offset + APPROVED_PAGE_SIZE)}
        >
          {trans('pagination_next')}
          <i className="icon fas fa-chevron-right" />
        </button>
      </div>
    );
  }

  // ── Tab control + cross-state coordination ─────────────────────────────

  switchTab(status: RequestTab): void {
    if (this.tab === status) return;
    this.tab = status;

    if (status === 'approved' && this.approved.rows.length === 0) {
      this.approved.load();
    }

    m.redraw();
  }

  /**
   * Wrap requests.act() so that approve/revoke also refreshes the approved
   * users state — those actions move users between the two lists.
   */
  async actOnRequest(req: VerificationRequest, action: RequestAction): Promise<void> {
    const ok = await this.requests.act(req, action);
    if (ok && (action === 'approve' || action === 'revoke')) {
      this.approved.load();
    }
  }

  /**
   * Wrap approved.revokeUser() so the requests list also refreshes — the
   * underlying request row flips status.
   */
  async revokeApprovedUser(row: ApprovedUserRow): Promise<void> {
    const ok = await this.approved.revokeUser(row);
    if (ok) this.requests.load();
  }

  formatDocType(type: string | null | undefined): string {
    if (!type) return '';
    const configured = app.forum.attribute<Array<{ id: string; label: string }>>('ramonVerifiedDocumentTypes');
    if (Array.isArray(configured)) {
      const match = configured.find((t) => t && t.id === type);
      if (match) return match.label;
    }
    const fallback: Record<string, string> = {
      rg: 'RG',
      cpf: 'CPF',
      passport: 'Passport',
      driver: "Driver's license",
      other: 'Other',
    };
    return fallback[type] || type;
  }

  renderItem(req: VerificationRequest): Mithril.Children {
    const user = req.user() as User | false;
    const handler = req.handler() as User | false;
    const docPath = req.documentPath();
    const reqId = String(req.id() ?? '');
    const docUrl = docPath && reqId ? app.forum.attribute('apiUrl') + '/verified/documents/' + reqId : null;
    const busy = !!this.requests.busy[reqId];

    return (
      <li className="VerifiedRequest" key={req.id()}>
        <div className="VerifiedRequest-main">
          <div className="VerifiedRequest-user">
            {user ? username(user) : <span className="username">—</span>}
            <span className={'VerifiedRequest-status VerifiedRequest-status--' + req.status()}>
              {trans('status_' + req.status())}
            </span>
          </div>

          <div className="VerifiedRequest-meta helpText">
            <span title={req.createdAt() ? (req.createdAt() as Date).toString() : ''}>{humanTime(req.createdAt())}</span>
            {handler && typeof handler.displayName === 'function' && req.handledAt() ? (
              <span>
                {' · '}
                {trans('handled_by', {
                  handlerName: handler.displayName(),
                  date: extractText(humanTime(req.handledAt())),
                })}
              </span>
            ) : null}
          </div>

          {req.reason() ? (
            <blockquote className="VerifiedRequest-reason">{req.reason()}</blockquote>
          ) : null}

          {docPath && docUrl ? (
            <div className="VerifiedRequest-document">
              <i className="icon fas fa-id-card" />
              <span className="VerifiedRequest-docType">{this.formatDocType(req.documentType())}</span>
              <button
                type="button"
                className="VerifiedRequest-docLink"
                onclick={() => app.modal.show(DocumentPreviewModal, {
                  url: docUrl,
                  filename: docPath,
                  typeLabel: this.formatDocType(req.documentType()),
                })}
              >
                <i className="icon fas fa-eye" />
                {trans('view_document')}
              </button>
            </div>
          ) : null}

          {req.adminNote() ? (
            <div className="VerifiedRequest-note">
              <strong>{trans('admin_note_label')}:</strong> {req.adminNote()}
            </div>
          ) : null}
        </div>

        <div className="VerifiedRequest-actions">
          {req.isPending() ? [
            <Button className="Button Button--primary" loading={busy} onclick={() => this.actOnRequest(req, 'approve')}>
              <i className="icon fas fa-check" /> {trans('approve_button')}
            </Button>,
            <Button className="Button Button--danger" loading={busy} onclick={() => this.actOnRequest(req, 'reject')}>
              <i className="icon fas fa-times" /> {trans('reject_button')}
            </Button>,
          ] : req.isApproved() ? (
            <Button className="Button Button--danger" loading={busy} onclick={() => this.actOnRequest(req, 'revoke')}>
              <i className="icon fas fa-ban" /> {trans('revoke_button')}
            </Button>
          ) : null}
        </div>
      </li>
    );
  }
}
