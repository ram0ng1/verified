import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import username from 'flarum/common/helpers/username';
import DocumentPreviewModal from './DocumentPreviewModal';

const TABS = ['pending', 'approved', 'rejected'];
const APPROVED_PAGE_SIZE = 15;
const SEARCH_DEBOUNCE_MS = 250;

const trans = (key, params) => app.translator.trans(`ramon-verified.admin.requests.${key}`, params);

export default class VerificationRequestsSection extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'pending';
    this.loading = false;
    this.requests = [];
    this.busy = {};

    // Approved tab state — driven by the dedicated paginated endpoint so
    // group-permission verifications (which have no request row) show up too.
    this.approved = {
      loading: false,
      rows: [],
      total: 0,
      offset: 0,
      query: '',
    };
    this._searchTimer = null;

    this.load();
    // Load the approved count immediately so the tab badge is accurate even
    // before the user clicks the tab. The cost is one extra GET on mount.
    this.loadApproved();
  }

  view() {
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

  renderRequestTab() {
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

  renderApprovedTab() {
    const { loading, rows, total, offset, query } = this.approved;
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
            oninput={(e) => this.onSearchInput(e.target.value)}
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

  renderApprovedItem(row) {
    const busy = !!this.busy['user-' + row.id];
    const isGroupOnly = row.source === 'group';

    return (
      <li className={'VerifiedRequest' + (isGroupOnly ? ' VerifiedRequest--auto' : '')} key={'user-' + row.id}>
        <div className="VerifiedRequest-main">
          <div className="VerifiedRequest-user">
            <span className="username">{row.displayName || row.username}</span>
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

  renderRequestMeta(req) {
    const docPath = req.documentPath;
    const docUrl = docPath ? app.forum.attribute('apiUrl') + '/verified/documents/' + req.id : null;
    const created = req.createdAt ? new Date(req.createdAt) : null;
    const handled = req.handledAt ? new Date(req.handledAt) : null;
    const handlerName = req.handler ? (req.handler.displayName || req.handler.username) : null;

    return [
      <div className="VerifiedRequest-meta helpText">
        <span title={created && created.toString()}>{humanTime(created)}</span>
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

      docPath ? (
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

  renderPagination(offset, total) {
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

  switchTab(status) {
    if (this.tab === status) return;
    this.tab = status;

    if (status === 'approved' && this.approved.rows.length === 0) {
      this.loadApproved();
    }

    m.redraw();
  }

  onSearchInput(value) {
    this.approved.query = value;
    this.approved.offset = 0;

    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.loadApproved(), SEARCH_DEBOUNCE_MS);

    m.redraw();
  }

  goToPage(offset) {
    this.approved.offset = Math.max(0, offset);
    this.loadApproved();
  }

  loadApproved() {
    const { query, offset } = this.approved;
    this.approved.loading = true;
    m.redraw();

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/verified/approved-users',
        params: {
          q: query,
          offset,
          limit: APPROVED_PAGE_SIZE,
        },
      })
      .then((res) => {
        this.approved.loading = false;
        this.approved.rows = (res && res.data) || [];
        this.approved.total = (res && res.meta && res.meta.total) || 0;
        m.redraw();
      })
      .catch(() => {
        this.approved.loading = false;
        m.redraw();
      });
  }

  revokeUser(row) {
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
        // Reload both lists (revoke writes a rejection row that should appear
        // in the rejected tab; approved list needs the row gone).
        this.load();
        this.loadApproved();
      })
      .catch(() => {
        delete this.busy['user-' + row.id];
        m.redraw();
      });
  }

  /**
   * Tab-aware filtering for request-driven tabs (pending, rejected).
   * The approved tab uses a dedicated paginated endpoint and does NOT
   * pass through this method.
   */
  filteredRequests() {
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

  /**
   * Returns a Map<userId, latestRequest> keyed by user id, where the value
   * is the most recent verification request for that user (latest by
   * createdAt, falling back to id).
   */
  latestRequestPerUser() {
    const map = new Map();
    for (const req of this.requests) {
      const user = req.user();
      if (!user) continue;
      const userId = user.id();
      const existing = map.get(userId);
      if (!existing) {
        map.set(userId, req);
        continue;
      }
      const a = req.createdAt() ? req.createdAt().getTime() : 0;
      const b = existing.createdAt() ? existing.createdAt().getTime() : 0;
      if (a > b || (a === b && parseInt(req.id(), 10) > parseInt(existing.id(), 10))) {
        map.set(userId, req);
      }
    }
    return map;
  }

  countByStatus() {
    // Pending and rejected counts are derived from the requests list we
    // already loaded. Approved comes from the dedicated endpoint's `total`
    // (so it accounts for group-permission verifications without a request
    // row, AND covers users beyond the current page).
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

  renderItem(req) {
    const user = req.user();
    const handler = req.handler();
    const docPath = req.documentPath();
    const docUrl = docPath ? app.forum.attribute('apiUrl') + '/verified/documents/' + req.id() : null;
    const busy = !!this.busy[req.id()];

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
            <span title={req.createdAt() && req.createdAt().toString()}>{humanTime(req.createdAt())}</span>
            {handler && typeof handler.displayName === 'function' && req.handledAt() ? (
              <span>
                {' · '}
                {trans('handled_by', {
                  // Don't use the key `user` — Flarum's Translator auto-wraps
                  // params named `user` with the username() helper, which expects
                  // a User model, not a string.
                  handlerName: handler.displayName(),
                  date: extractText(humanTime(req.handledAt())),
                })}
              </span>
            ) : null}
          </div>

          {req.reason() ? (
            <blockquote className="VerifiedRequest-reason">{req.reason()}</blockquote>
          ) : null}

          {docPath ? (
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

  formatDocType(type) {
    if (!type) return '';
    // Admin-configurable labels — read from the forum payload so a forum
    // outside Brazil (or one that's renamed RG → e.g. "National ID") sees
    // the right label here too. Fall back to the historical hardcoded list
    // for stored requests whose id no longer exists in config.
    const configured = app.forum.attribute('ramonVerifiedDocumentTypes');
    if (Array.isArray(configured)) {
      const match = configured.find((t) => t && t.id === type);
      if (match) return match.label;
    }
    const fallback = {
      rg: 'RG',
      cpf: 'CPF',
      passport: 'Passport',
      driver: "Driver's license",
      other: 'Other',
    };
    return fallback[type] || type;
  }

  load() {
    this.loading = true;
    this.requests = [];

    app.store
      .find('verification-requests', {
        sort: '-createdAt',
        page: { limit: 100 },
        include: 'user,handler',
      })
      .then((res) => {
        this.loading = false;
        const list = Array.isArray(res) ? res.slice() : [];
        list.sort((a, b) => (b.createdAt() || 0) - (a.createdAt() || 0));
        this.requests = list;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  act(req, action) {
    let note = null;
    if (action === 'reject' || action === 'revoke') {
      note = window.prompt(extractText(trans(action + '_prompt')));
      if (note === null) return;
    } else if (action === 'approve') {
      const ans = window.prompt(extractText(trans('approve_prompt')));
      if (ans === null) return;
      note = ans;
    }

    this.busy[req.id()] = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/verification-requests/' + req.id() + '/' + action,
        body: { meta: { adminNote: note || '' } },
      })
      .then((res) => {
        delete this.busy[req.id()];
        if (res && res.data) app.store.pushPayload(res);
        this.load();
        // Approve/revoke change the approved-users list — refresh it so the
        // tab badge and the approved tab both reflect the new state.
        if (action === 'approve' || action === 'revoke') this.loadApproved();
        app.alerts.show({ type: 'success' }, trans(action + '_success'));
      })
      .catch(() => {
        delete this.busy[req.id()];
        m.redraw();
      });
  }
}
