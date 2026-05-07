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
import DocumentPreviewModal from './DocumentPreviewModal';
import promptTier from '../../common/utils/promptTier';

type RequestTab = 'pending' | 'approved' | 'rejected';
const TABS: RequestTab[] = ['pending', 'approved', 'rejected'];
const APPROVED_PAGE_SIZE = 15;
const SEARCH_DEBOUNCE_MS = 250;

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.requests.${key}`, params ?? {});

interface ApprovedRequestSummary {
  id: string | number;
  documentPath?: string | null;
  documentType?: string | null;
  reason?: string | null;
  adminNote?: string | null;
  createdAt?: string | null;
  handledAt?: string | null;
  handler?: { displayName?: string; username?: string } | null;
}

interface ApprovedGroup {
  id: string | number;
  name?: string;
}

interface ApprovedUserRow {
  id: string | number;
  username?: string;
  displayName?: string;
  source?: 'request' | 'group';
  verifiedTier?: string | null;
  request?: ApprovedRequestSummary | null;
  autoVerifiedGroups?: ApprovedGroup[];
}

interface TierMeta {
  id: string;
  label: string;
  color: string;
}

interface ApprovedState {
  loading: boolean;
  rows: ApprovedUserRow[];
  total: number;
  offset: number;
  query: string;
  tierFilter: string; // '' = all tiers
  tiers: TierMeta[];
}

export default class VerificationRequestsSection extends Component<ComponentAttrs> {
  protected tab: RequestTab = 'pending';
  protected loading: boolean = false;
  protected requests: VerificationRequest[] = [];
  protected busy: Record<string, boolean> = {};
  protected approved: ApprovedState = {
    loading: false,
    rows: [],
    total: 0,
    offset: 0,
    query: '',
    tierFilter: '',
    tiers: [],
  };
  private _searchTimer: ReturnType<typeof setTimeout> | null = null;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.tab = 'pending';
    this.loading = false;
    this.requests = [];
    this.busy = {};
    this.approved = {
      loading: false,
      rows: [],
      total: 0,
      offset: 0,
      query: '',
      tierFilter: '',
      tiers: [],
    };
    this._searchTimer = null;

    this.load();
    this.loadApproved();
  }

  view(): Mithril.Children {
    const counts = this.countByStatus();

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
    if (this.loading) {
      return (
        <div className="VerifiedRequests-empty">
          <LoadingIndicator />
        </div>
      );
    }

    const list = this.filteredRequests();
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
            oninput={(e: Event) => this.onSearchInput((e.target as HTMLInputElement).value)}
          />
          {query ? (
            <button
              type="button"
              className="VerifiedRequests-search-clear"
              onclick={() => this.onSearchInput('')}
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
            onclick={() => this.setTierFilter('')}
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
                onclick={() => this.setTierFilter(t.id)}
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
    const busy = !!this.busy['user-' + row.id];
    const isGroupOnly = row.source === 'group';
    const tier = this.findTier(row.verifiedTier);
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
              onclick={() => this.revokeUser(row)}
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
          onclick={() => this.goToPage(offset - APPROVED_PAGE_SIZE)}
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
          onclick={() => this.goToPage(offset + APPROVED_PAGE_SIZE)}
        >
          {trans('pagination_next')}
          <i className="icon fas fa-chevron-right" />
        </button>
      </div>
    );
  }

  // ── Tab + data control ──────────────────────────────────────────────────

  switchTab(status: RequestTab): void {
    if (this.tab === status) return;
    this.tab = status;

    if (status === 'approved' && this.approved.rows.length === 0) {
      this.loadApproved();
    }

    m.redraw();
  }

  onSearchInput(value: string): void {
    this.approved.query = value;
    this.approved.offset = 0;

    if (this._searchTimer) clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.loadApproved(), SEARCH_DEBOUNCE_MS);

    m.redraw();
  }

  goToPage(offset: number): void {
    this.approved.offset = Math.max(0, offset);
    this.loadApproved();
  }

  setTierFilter(tierId: string): void {
    if (this.approved.tierFilter === tierId) return;
    this.approved.tierFilter = tierId;
    this.approved.offset = 0;
    this.loadApproved();
  }

  /**
   * Lookup the tier metadata loaded with the approved-users response so we
   * can render a colored chip next to each row's name.
   */
  protected findTier(tierId: string | null | undefined): TierMeta | null {
    if (!tierId) return null;
    return this.approved.tiers.find((t) => t.id === tierId) || null;
  }

  loadApproved(): void {
    const { query, offset } = this.approved;
    this.approved.loading = true;
    m.redraw();

    const params: Record<string, string | number> = {
      q: query,
      offset,
      limit: APPROVED_PAGE_SIZE,
    };
    if (this.approved.tierFilter) params.tier = this.approved.tierFilter;

    app
      .request<{ data?: ApprovedUserRow[]; meta?: { total?: number; tiers?: TierMeta[] } }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/verified/approved-users',
        params,
      })
      .then((res) => {
        this.approved.loading = false;
        this.approved.rows = (res && res.data) || [];
        this.approved.total = (res && res.meta && res.meta.total) || 0;
        if (res && res.meta && res.meta.tiers) this.approved.tiers = res.meta.tiers;
        m.redraw();
      })
      .catch(() => {
        this.approved.loading = false;
        m.redraw();
      });
  }

  revokeUser(row: ApprovedUserRow): void {
    const note = window.prompt(extractText(trans('revoke_prompt')));
    if (note === null) return;

    this.busy['user-' + row.id] = true;
    m.redraw();

    app
      .request({
        method: 'DELETE',
        url: app.forum.attribute('apiUrl') + '/verified/users/' + row.id + '/verify',
        body: { adminNote: note || '' },
      })
      .then(() => {
        delete this.busy['user-' + row.id];
        app.alerts.show({ type: 'success' }, trans('revoke_success'));
        this.load();
        this.loadApproved();
      })
      .catch(() => {
        delete this.busy['user-' + row.id];
        m.redraw();
      });
  }

  filteredRequests(): VerificationRequest[] {
    if (this.tab === 'pending') {
      return this.requests.filter((r) => r.status() === 'pending');
    }

    if (this.tab === 'rejected') {
      const latestPerUser = this.latestRequestPerUser();
      return Array.from(latestPerUser.values()).filter((r) => {
        if (r.status() !== 'rejected') return false;
        const user = r.user();
        return !user || !user.isVerified || !user.isVerified();
      });
    }

    return [];
  }

  latestRequestPerUser(): Map<string, VerificationRequest> {
    const map = new Map<string, VerificationRequest>();
    for (const req of this.requests) {
      const user = req.user();
      if (!user) continue;
      const userId = String(user.id() ?? '');
      if (!userId) continue;
      const existing = map.get(userId);
      if (!existing) {
        map.set(userId, req);
        continue;
      }
      const a = req.createdAt() ? (req.createdAt() as Date).getTime() : 0;
      const b = existing.createdAt() ? (existing.createdAt() as Date).getTime() : 0;
      if (a > b || (a === b && parseInt(String(req.id() ?? '0'), 10) > parseInt(String(existing.id() ?? '0'), 10))) {
        map.set(userId, req);
      }
    }
    return map;
  }

  countByStatus(): Record<RequestTab, number> {
    const pendingCount = this.requests.filter((r) => r.status() === 'pending').length;

    const latestPerUser = this.latestRequestPerUser();
    let rejectedCount = 0;
    for (const r of latestPerUser.values()) {
      const u = r.user();
      const isVerified = u && u.isVerified && u.isVerified();
      if (r.status() === 'rejected' && !isVerified) rejectedCount++;
    }

    return {
      pending: pendingCount,
      approved: this.approved.total,
      rejected: rejectedCount,
    };
  }

  renderItem(req: VerificationRequest): Mithril.Children {
    const user = req.user() as User | false;
    const handler = req.handler() as User | false;
    const docPath = req.documentPath();
    const reqId = String(req.id() ?? '');
    const docUrl = docPath && reqId ? app.forum.attribute('apiUrl') + '/verified/documents/' + reqId : null;
    const busy = !!this.busy[reqId];

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
            <Button className="Button Button--primary" loading={busy} onclick={() => this.act(req, 'approve')}>
              <i className="icon fas fa-check" /> {trans('approve_button')}
            </Button>,
            <Button className="Button Button--danger" loading={busy} onclick={() => this.act(req, 'reject')}>
              <i className="icon fas fa-times" /> {trans('reject_button')}
            </Button>,
          ] : req.isApproved() ? (
            <Button className="Button Button--danger" loading={busy} onclick={() => this.act(req, 'revoke')}>
              <i className="icon fas fa-ban" /> {trans('revoke_button')}
            </Button>
          ) : null}
        </div>
      </li>
    );
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

  load(): void {
    this.loading = true;
    this.requests = [];

    app.store
      .find<VerificationRequest[]>('verification-requests', {
        sort: '-createdAt',
        page: { limit: 100 },
        include: 'user,handler',
      })
      .then((res) => {
        this.loading = false;
        const list: VerificationRequest[] = Array.isArray(res) ? res.slice() : [];
        list.sort((a, b) => {
          const av = a.createdAt() ? (a.createdAt() as Date).getTime() : 0;
          const bv = b.createdAt() ? (b.createdAt() as Date).getTime() : 0;
          return bv - av;
        });
        this.requests = list;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  act(req: VerificationRequest, action: 'approve' | 'reject' | 'revoke'): void {
    let note: string | null = null;
    let tierId: string | null = null;

    if (action === 'reject' || action === 'revoke') {
      note = window.prompt(extractText(trans(action + '_prompt')));
      if (note === null) return;
    } else if (action === 'approve') {
      // Pick tier first — no point asking for a note then bailing on tier.
      const tier = promptTier();
      if (!tier) return;
      tierId = tier.id;

      const ans = window.prompt(extractText(trans('approve_prompt')));
      if (ans === null) return;
      note = ans;
    }

    const reqId = String(req.id() ?? '');
    if (!reqId) return;

    this.busy[reqId] = true;
    m.redraw();

    const body: Record<string, unknown> = { meta: { adminNote: note || '' } };
    if (tierId) (body.meta as Record<string, unknown>).tier = tierId;

    app
      .request<{ data?: unknown }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/verification-requests/' + reqId + '/' + action,
        body,
      })
      .then((res) => {
        delete this.busy[reqId];
        if (res && res.data) app.store.pushPayload(res as any);
        this.load();
        if (action === 'approve' || action === 'revoke') this.loadApproved();
        app.alerts.show({ type: 'success' }, trans(action + '_success'));
      })
      .catch(() => {
        delete this.busy[reqId];
        m.redraw();
      });
  }
}
