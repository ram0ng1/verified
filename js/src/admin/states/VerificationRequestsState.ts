import app from "flarum/admin/app";
import extractText from "flarum/common/utils/extractText";

import type VerificationRequest from "../../common/models/VerificationRequest";
import apiCall from "../../common/utils/apiCall";
import promptTier from "../../common/utils/promptTier";

export type RequestAction = "approve" | "reject" | "revoke";
export type RequestTab = "pending" | "approved" | "rejected";

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.requests.${key}`, params ?? {});

/**
 * Owns the list of pending and rejected verification requests, plus the
 * "approve / reject / revoke" actions on individual requests.
 *
 * The approved tab uses its own state ({@link ApprovedUsersState}) because
 * it has a separate paginated/searchable endpoint. Coordination between the
 * two states (refresh both on approve/revoke) is the responsibility of the
 * component that holds both — see VerificationRequestsSection.
 */
export default class VerificationRequestsState {
  loading: boolean = false;
  requests: VerificationRequest[] = [];
  busy: Record<string, boolean> = {};

  async load(): Promise<void> {
    this.loading = true;
    this.requests = [];

    try {
      const res = await app.store.find<VerificationRequest[]>(
        "verification-requests",
        {
          sort: "-createdAt",
          page: { limit: 100 },
          include: "user,handler",
        },
      );
      const list: VerificationRequest[] = Array.isArray(res) ? res.slice() : [];
      list.sort((a, b) => {
        const av = a.createdAt() ? (a.createdAt() as Date).getTime() : 0;
        const bv = b.createdAt() ? (b.createdAt() as Date).getTime() : 0;
        return bv - av;
      });
      this.requests = list;
    } catch (err) {
      app.alerts.show({ type: "error" }, extractText(trans("load_failed")));
    } finally {
      this.loading = false;
      m.redraw();
    }
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
      // Pick tier first — no point asking for a note then bailing on tier.
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
      this.load();
      app.alerts.show({ type: "success" }, trans(action + "_success"));
      m.redraw();
      return true;
    }

    m.redraw();
    return false;
  }

  pendingCount(): number {
    return this.requests.filter((r) => r.status() === "pending").length;
  }

  rejectedCount(): number {
    let count = 0;
    for (const r of this.latestRequestPerUser().values()) {
      const u = r.user();
      const isVerified = u && u.isVerified && u.isVerified();
      if (r.status() === "rejected" && !isVerified) count++;
    }
    return count;
  }

  filteredRequests(tab: RequestTab): VerificationRequest[] {
    if (tab === "pending") {
      return this.requests.filter((r) => r.status() === "pending");
    }

    if (tab === "rejected") {
      const latestPerUser = this.latestRequestPerUser();
      return Array.from(latestPerUser.values()).filter((r) => {
        if (r.status() !== "rejected") return false;
        const user = r.user();
        return !user || !user.isVerified || !user.isVerified();
      });
    }

    return [];
  }

  /**
   * For each user that appears in the loaded requests, return only the most
   * recent request. Used to decide whether the user belongs in the rejected
   * tab — a user with a later approved or pending request shouldn't show up
   * as "rejected".
   */
  latestRequestPerUser(): Map<string, VerificationRequest> {
    const map = new Map<string, VerificationRequest>();
    for (const req of this.requests) {
      const user = req.user();
      if (!user) continue;
      const userId = String(user.id() ?? "");
      if (!userId) continue;
      const existing = map.get(userId);
      if (!existing) {
        map.set(userId, req);
        continue;
      }
      const a = req.createdAt() ? (req.createdAt() as Date).getTime() : 0;
      const b = existing.createdAt()
        ? (existing.createdAt() as Date).getTime()
        : 0;
      if (
        a > b ||
        (a === b &&
          parseInt(String(req.id() ?? "0"), 10) >
            parseInt(String(existing.id() ?? "0"), 10))
      ) {
        map.set(userId, req);
      }
    }
    return map;
  }
}
