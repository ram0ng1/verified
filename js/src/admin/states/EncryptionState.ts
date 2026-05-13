import app from "flarum/admin/app";

import apiCall from "../../common/utils/apiCall";

export interface EncryptionStatus {
  available: boolean;
  has_public_key: boolean;
  private_key_present: boolean;
  keys_match: boolean | null;
  healthy: boolean;
  requires_regeneration: boolean;
  public_key: string | null;
}

export interface KeypairResult {
  privateKey: string;
  configKey: string;
  orphanedDocuments?: number;
}

const apiUrl = () =>
  (app.forum.attribute<string>("apiUrl") || "/api").replace(/\/+$/, "");

/**
 * Owns the encryption-keypair status and the API calls that mutate it.
 * The component holds an instance and delegates — the view never talks to
 * `app.request()` directly.
 */
export default class EncryptionState {
  status: EncryptionStatus | null = null;
  loading: boolean = true;

  async refresh(): Promise<void> {
    this.loading = true;
    const res = await apiCall<EncryptionStatus>(
      {
        method: "GET",
        url: `${apiUrl()}/verified/encryption/status`,
      },
      { errorKey: "ramon-verified.admin.requests.status_load_failed" }
    );
    this.status = res;
    this.loading = false;
    m.redraw();
  }

  /**
   * Generate a fresh keypair. Pass `acknowledgeLoss: true` only when the
   * caller has confirmed the irreversible loss of every existing encrypted
   * document.
   */
  async generate(
    acknowledgeLoss: boolean = false
  ): Promise<KeypairResult | null> {
    const body: Record<string, unknown> = {};
    if (acknowledgeLoss) body.acknowledgeLoss = true;

    const res = await apiCall<KeypairResult>(
      {
        method: "POST",
        url: `${apiUrl()}/verified/encryption/generate-keypair`,
        body,
      },
      { errorKey: "ramon-verified.admin.requests.generate_keypair_failed" }
    );
    if (!res) return null;

    await this.refresh();
    return res;
  }
}
