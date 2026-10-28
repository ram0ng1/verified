import app from 'flarum/admin/app';

import apiCall from '../../common/utils/apiCall';

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

const apiUrl = () => (app.forum.attribute<string>('apiUrl') || '/api').replace(/\/+$/, '');

export default class EncryptionState {
  status: EncryptionStatus | null = null;
  loading: boolean = true;

  async refresh(): Promise<void> {
    this.loading = true;
    const res = await apiCall<EncryptionStatus>(
      {
        method: 'GET',
        url: `${apiUrl()}/verified/encryption/status`,
      },
      { errorKey: 'ramon-verified.admin.requests.status_load_failed' }
    );
    this.status = res;
    this.loading = false;
    m.redraw();
  }

  // `acknowledgeLoss` só depois de o admin confirmar que os documentos já
  // cifrados se perdem para sempre.
  async generate(acknowledgeLoss: boolean = false): Promise<KeypairResult | null> {
    const body: Record<string, unknown> = {};
    if (acknowledgeLoss) body.acknowledgeLoss = true;

    const res = await apiCall<KeypairResult>(
      {
        method: 'POST',
        url: `${apiUrl()}/verified/encryption/generate-keypair`,
        body,
      },
      { errorKey: 'ramon-verified.admin.requests.generate_keypair_failed' }
    );
    if (!res) return null;

    await this.refresh();
    return res;
  }
}
