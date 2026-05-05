import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import username from 'flarum/common/helpers/username';

const TABS = ['pending', 'approved', 'rejected'];

const trans = (key, params) => app.translator.trans(`ramon-verified.admin.requests.${key}`, params);

export default class VerificationRequestsSection extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'pending';
    this.loading = false;
    this.requests = [];
    this.busy = {};
    this.load();
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
              onclick={() => {
                this.tab = status;
                m.redraw();
              }}
              role="tab"
              aria-selected={this.tab === status ? 'true' : 'false'}
            >
              <span>{trans('tab_' + status)}</span>
              <span className="VerifiedRequests-count">{counts[status] || 0}</span>
            </button>
          ))}
        </nav>

        <div className="VerifiedRequests-body">
          {this.loading ? (
            <div className="VerifiedRequests-empty">
              <LoadingIndicator />
            </div>
          ) : this.filteredRequests().length === 0 ? (
            <div className="VerifiedRequests-empty">
              <i className="icon fas fa-inbox" />
              <span>{trans('empty_' + this.tab)}</span>
            </div>
          ) : (
            <ul className="VerifiedRequests-list">{this.filteredRequests().map((r) => this.renderItem(r))}</ul>
          )}
        </div>
      </div>
    );
  }

  /**
   * Tab-aware filtering.
   *
   * - **Pending**: every request still in pending status (always at most one
   *   per user thanks to the backend).
   * - **Approved**: ONLY users who are currently verified, deduplicated by
   *   user_id (latest approved request per user). Re-approving the same user
   *   after a revoke must not produce two rows.
   * - **Rejected**: users whose latest action was a rejection AND who are
   *   not currently verified, deduplicated by user_id.
   */
  filteredRequests() {
    if (this.tab === 'pending') {
      return this.requests.filter((r) => r.status() === 'pending');
    }

    const latestPerUser = this.latestRequestPerUser();

    if (this.tab === 'approved') {
      return Array.from(latestPerUser.values()).filter((r) => {
        if (r.status() !== 'approved') return false;
        const user = r.user();
        // Only show users who are currently verified — handles the case where
        // a later DELETE flipped is_verified back to false but the request
        // row remained "approved" historically (we also write a rejection
        // row in that flow, but defensive check anyway).
        return user && user.isVerified && user.isVerified();
      });
    }

    if (this.tab === 'rejected') {
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
    // Counts mirror filteredRequests so the tab badges match the lists.
    const pendingCount = this.requests.filter((r) => r.status() === 'pending').length;

    const latestPerUser = this.latestRequestPerUser();
    let approvedCount = 0;
    let rejectedCount = 0;
    for (const r of latestPerUser.values()) {
      const u = r.user();
      const isVerified = u && u.isVerified && u.isVerified();
      if (r.status() === 'approved' && isVerified) approvedCount++;
      else if (r.status() === 'rejected' && !isVerified) rejectedCount++;
    }
    return { pending: pendingCount, approved: approvedCount, rejected: rejectedCount };
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
              <a href={docUrl} target="_blank" rel="noopener" className="VerifiedRequest-docLink">
                <i className="icon fas fa-external-link-alt" />
                {trans('view_document')}
              </a>
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
    const labels = {
      rg: 'RG',
      cpf: 'CPF',
      passport: 'Passport',
      driver: 'Driver’s license',
      other: 'Other',
    };
    return labels[type] || type;
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
        app.alerts.show({ type: 'success' }, trans(action + '_success'));
      })
      .catch(() => {
        delete this.busy[req.id()];
        m.redraw();
      });
  }
}
