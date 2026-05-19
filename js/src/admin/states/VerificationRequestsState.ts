import app from "flarum/admin/app";
import extractText from "flarum/common/utils/extractText";

import type VerificationRequest from "../../common/models/VerificationRequest";
import apiCall from "../../common/utils/apiCall";
import promptTier from "../../common/utils/promptTier";

export type RequestAction = "approve" | "reject" | "revoke";
export type RequestTab = "pending" | "approved" | "rejected";

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.requests.${key}`, params ?? {});

interface TabState {
  loading: boolean;
  loaded: boolean;
  requests: VerificationRequest[];
  total: number;
  offset: number;
}

export const REQUESTS_PAGE_SIZE = 25;

/**
 * Owns the pending and rejected verification request tabs. Each tab is loaded
 * independently from the server with `filter[status]=<tab>` so pagination
 * stays correct above 100 rows — the previous "load 100 once, filter
 * client-side" shape silently hid every record past the cap.
 *
 * The approved tab is handled by {@link ApprovedUsersState}; coordination
 * between this state and that one (refresh both on approve/revoke) is the
 * responsibility of `VerificationRequestsSection`.
 */
export default class VerificationRequestsState {
  busy: Record<string, boolean> = {};

  private tabs: Record<"pending" | "rejected", TabState> = {
    pending: { loading: false, loaded: false, requests: [], total: 0, offset: 0 },
    rejected: { loading: false, loaded: false, requests: [], total: 0, offset: 0 },
  };

  loadingFor(tab: "pending" | "rejected"): boolean {
    return this.tabs[tab].loading;
  }

  requestsFor(tab: "pending" | "rejected"): VerificationRequest[] {
    return this.tabs[tab].requests;
  }

  totalFor(tab: "pending" | "rejected"): number {
    return this.tabs[tab].total;
  }

  offsetFor(tab: "pending" | "rejected"): number {
    return this.tabs[tab].offset;
  }

  pendingCount(): number {
    return this.tabs.pending.total;
  }

  rejectedCount(): number {
    return this.tabs.rejected.total;
  }

  hiddenFor(tab: "pending" | "rejected"): number {
    const state = this.tabs[tab];
    return Math.max(0, state.total - state.requests.length - state.offset);
  }

  /**
   * Carrega a página atual de uma aba. `offset` é o índice 0-based dentro
   * do conjunto filtrado pelo status; `total` vem do `meta.page.total` do
   * JSON:API e dirige paginação no UI.
   */
  async load(tab: "pending" | "rejected", offset: number = 0): Promise<void> {
    const state = this.tabs[tab];
    state.loading = true;
    state.offset = Math.max(0, offset);
    m.redraw();

    try {
      const res = await app.store.find<VerificationRequest[]>(
        "verification-requests",
        {
          sort: "-createdAt",
          filter: { status: tab },
          page: { limit: REQUESTS_PAGE_SIZE, offset: state.offset },
          include: "user,handler",
        },
      );

      const meta = (
        res as { payload?: { meta?: { page?: { total?: number } } } }
      ).payload?.meta?.page;
      state.total = meta && typeof meta.total === "number" ? meta.total : 0;

      const list: VerificationRequest[] = Array.isArray(res) ? res.slice() : [];
      list.sort((a, b) => {
        const av = a.createdAt() ? (a.createdAt() as Date).getTime() : 0;
        const bv = b.createdAt() ? (b.createdAt() as Date).getTime() : 0;
        return bv - av;
      });
      state.requests = list;
      state.loaded = true;
    } catch (err) {
      app.alerts.show({ type: "error" }, extractText(trans("load_failed")));
    } finally {
      state.loading = false;
      m.redraw();
    }
  }

  /**
   * Carrega ambas as abas — útil no boot para já ter os contadores prontos.
   * Roda em paralelo; falha em uma aba não impede a outra.
   */
  async loadAll(): Promise<void> {
    await Promise.all([this.load("pending", 0), this.load("rejected", 0)]);
  }

  goToPage(tab: "pending" | "rejected", offset: number): void {
    this.load(tab, offset);
  }

  /**
   * Approve, reject, or revoke a request. Returns true on success.
   * Callers should refresh the approved-users state when the action is
   * `approve` or `revoke`.
   */
  async act(req: VerificationRequest, action: RequestAction): Promise<boolean> {
    let note: string | null = null;
    let tierId: string | null = null;

    if (action === "reject" || action === "revoke") {
      note = window.prompt(extractText(trans(action + "_prompt")));
      if (note === null) return false;
    } else if (action === "approve") {
      const tier = promptTier();
      if (!tier) return false;
      tierId = tier.id;

      const ans = window.prompt(extractText(trans("approve_prompt")));
      if (ans === null) return false;
      note = ans;
    }

    const reqId = String(req.id() ?? "");
    if (!reqId) return false;

    this.busy[reqId] = true;
    m.redraw();

    const body: Record<string, unknown> = { meta: { adminNote: note || "" } };
    if (tierId) (body.meta as Record<string, unknown>).tier = tierId;

    const res = await apiCall<{ data?: unknown }>(
      {
        method: "POST",
        url:
          app.forum.attribute("apiUrl") +
          "/verification-requests/" +
          reqId +
          "/" +
          action,
        body,
      },
      { errorKey: "ramon-verified.admin.requests.decide_failed" },
    );

    delete this.busy[reqId];

    if (res !== null) {
      if (res.data) app.store.pushPayload(res as any);
      await this.loadAll();
      app.alerts.show({ type: "success" }, trans(action + "_success"));
      m.redraw();
      return true;
    }

    m.redraw();
    return false;
  }
}
