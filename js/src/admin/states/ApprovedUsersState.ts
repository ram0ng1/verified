import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';

import apiCall from '../../common/utils/apiCall';

export const APPROVED_PAGE_SIZE = 15;
const SEARCH_DEBOUNCE_MS = 250;

const trans = (key: string) => app.translator.trans(`ramon-verified.admin.requests.${key}`);

export interface ApprovedRequestSummary {
  id: string | number;
  documentPath?: string | null;
  documentType?: string | null;
  reason?: string | null;
  adminNote?: string | null;
  createdAt?: string | null;
  handledAt?: string | null;
  handler?: { displayName?: string; username?: string } | null;
}

export interface ApprovedGroup {
  id: string | number;
  name?: string;
}

export interface ApprovedUserRow {
  id: string | number;
  username?: string;
  displayName?: string;
  source?: 'request' | 'group';
  verifiedTier?: string | null;
  request?: ApprovedRequestSummary | null;
  autoVerifiedGroups?: ApprovedGroup[];
}

export interface TierMeta {
  id: string;
  label: string;
  color: string;
}

/**
 * Owns the paginated, searchable, tier-filterable list of approved /
 * auto-verified users. Talks to the bespoke `/verified/approved-users`
 * endpoint (not a JSON:API resource — there is no model for these rows).
 */
export default class ApprovedUsersState {
  loading: boolean = false;
  rows: ApprovedUserRow[] = [];
  total: number = 0;
  offset: number = 0;
  query: string = '';
  /** Empty string = no filter. Otherwise the tier id. */
  tierFilter: string = '';
  tiers: TierMeta[] = [];
  busy: Record<string, boolean> = {};

  private _searchTimer: ReturnType<typeof setTimeout> | null = null;

  async load(): Promise<void> {
    this.loading = true;
    m.redraw();

    const params: Record<string, string | number> = {
      q: this.query,
      offset: this.offset,
      limit: APPROVED_PAGE_SIZE,
    };
    if (this.tierFilter) params.tier = this.tierFilter;

    const res = await apiCall<{ data?: ApprovedUserRow[]; meta?: { total?: number; tiers?: TierMeta[] } }>(
      {
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/verified/approved-users',
        params,
      },
      { errorKey: 'ramon-verified.admin.requests.load_approved_failed' }
    );

    this.loading = false;
    if (res) {
      this.rows = res.data || [];
      this.total = (res.meta && res.meta.total) || 0;
      if (res.meta && res.meta.tiers) this.tiers = res.meta.tiers;
    }
    m.redraw();
  }

  /**
   * Update the search query and debounce a reload. Resets pagination so the
   * user always sees results from the first page on a new search.
   */
  setSearch(value: string): void {
    this.query = value;
    this.offset = 0;

    if (this._searchTimer) clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.load(), SEARCH_DEBOUNCE_MS);

    m.redraw();
  }

  goToPage(offset: number): void {
    this.offset = Math.max(0, offset);
    this.load();
  }

  setTierFilter(tierId: string): void {
    if (this.tierFilter === tierId) return;
    this.tierFilter = tierId;
    this.offset = 0;
    this.load();
  }

  findTier(tierId: string | null | undefined): TierMeta | null {
    if (!tierId) return null;
    return this.tiers.find((t) => t.id === tierId) || null;
  }

  /**
   * Revoke a user's verification from the approved-users tab. Returns true
   * on success — callers should also refresh the requests state, since the
   * matching request row's status flips alongside.
   */
  async revokeUser(row: ApprovedUserRow): Promise<boolean> {
    const note = window.prompt(extractText(trans('revoke_prompt')));
    if (note === null) return false;

    const key = 'user-' + row.id;
    this.busy[key] = true;
    m.redraw();

    const res = await apiCall(
      {
        method: 'DELETE',
        url: app.forum.attribute('apiUrl') + '/verified/users/' + row.id + '/verify',
        body: { adminNote: note || '' },
      },
      { errorKey: 'ramon-verified.admin.requests.revoke_user_failed' }
    );

    delete this.busy[key];

    if (res !== null) {
      app.alerts.show({ type: 'success' }, trans('revoke_success'));
      this.load();
      m.redraw();
      return true;
    }

    m.redraw();
    return false;
  }
}
