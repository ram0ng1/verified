import app from "flarum/admin/app";
import extractText from "flarum/common/utils/extractText";

import VerificationRequest from "../../common/models/VerificationRequest";
import apiCall from "../../common/utils/apiCall";
import verificationPrompt from "../../common/utils/verificationPrompt";
import { getConfiguredTiers } from "../../common/utils/tiers";

interface JsonApiResource {
  type: string;
  id: string | number;
}

interface JsonApiListPayload {
  data: JsonApiResource[];
  included?: JsonApiResource[];
  meta?: { page?: { total?: number } };
}

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
    pending: {
      loading: false,
      loaded: false,
      requests: [],
      total: 0,
      offset: 0,
    },
    rejected: {
      loading: false,
      loaded: false,
      requests: [],
      total: 0,
      offset: 0,
    },
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
      // `app.request` em vez de `app.store.find` porque o backend lê
      // `byStatus=...` como query param custom — duas regras do
      // Flarum 2 / json-api-server tornam isso necessário:
      //   1. `AbstractDatabaseResource::filters()` é `final` e lança "use
      //      a model searcher" sempre que o JSON:API server detecta um
      //      `filter[]` no request.
      //   2. `JsonApi::validateQueryParameters` rejeita query params
      //      cujo nome seja só `[a-z]` minúsculo (`status` falha;
      //      `byStatus` passa). Por isso o camelCase obrigatório.
      // Integração com o store via `pushPayload`, preservando cache em
      // memória e hidratação das relações `user`/`handler`.
      const params = new URLSearchParams();
      params.set("sort", "-createdAt");
      params.set("byStatus", tab);
      params.set("page[limit]", String(REQUESTS_PAGE_SIZE));
      params.set("page[offset]", String(state.offset));
      params.set("include", "user,handler");

      const payload = await app.request<JsonApiListPayload>({
        method: "GET",
        url:
          app.forum.attribute("apiUrl") +
          "/verification-requests?" +
          params.toString(),
      });

      app.store.pushPayload(payload);

      const total = payload?.meta?.page?.total;
      state.total = typeof total === "number" ? total : 0;

      const list: VerificationRequest[] = (payload?.data ?? [])
        .map((row) =>
          app.store.getById<VerificationRequest>(
            "verification-requests",
            String(row.id),
          ),
        )
        .filter(
          (m): m is VerificationRequest => m instanceof VerificationRequest,
        );

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
    const withTier = action === "approve";

    // Preserve the previous "no tiers configured" guard — approval needs a
    // tier, and picking from an empty list would be a dead end.
    if (withTier && getConfiguredTiers().length === 0) {
      app.alerts.show(
        { type: "error" },
        app.translator.trans("ramon-verified.lib.tier_prompt.no_tiers"),
      );
      return false;
    }

    const result = await verificationPrompt({
      title: trans(action + "_button"),
      noteLabel: trans(action + "_prompt"),
      confirmLabel: trans(action + "_button"),
      withTier,
    });
    if (!result) return false;

    const reqId = String(req.id() ?? "");
    if (!reqId) return false;

    this.busy[reqId] = true;
    m.redraw();

    const body: Record<string, unknown> = {
      meta: { adminNote: result.note },
    };
    if (result.tier) (body.meta as Record<string, unknown>).tier = result.tier;

    const res = await apiCall<JsonApiListPayload | { data?: JsonApiResource }>(
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
      if (res.data) app.store.pushPayload(res as JsonApiListPayload);
      await this.loadAll();
      app.alerts.show({ type: "success" }, trans(action + "_success"));
      m.redraw();
      return true;
    }

    m.redraw();
    return false;
  }
}
