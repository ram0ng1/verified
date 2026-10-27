import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';
import type { FlarumRequestOptions } from 'flarum/common/Application';

interface ApiCallOptions {
  errorKey?: string;
  rethrow?: boolean;
  // Sem alerta; a falha continua resolvendo null.
  silent?: boolean;
}

interface FlarumApiError {
  response?: {
    errors?: Array<{ detail?: string; title?: string; code?: string }>;
  };
  message?: string;
  status?: number;
}

// Em caso de falha mostra o alerta e resolve null, para quem chama não
// repetir o mesmo .catch().
export default async function apiCall<T = unknown>(options: FlarumRequestOptions<T>, opts: ApiCallOptions = {}): Promise<T | null> {
  try {
    // Sem o `errorHandler` o core mostraria um segundo alerta.
    return await app.request<T>({ errorHandler: () => {}, ...options });
  } catch (raw) {
    const err = raw as FlarumApiError;

    if (!opts.silent) {
      const detail = err?.response?.errors?.[0]?.detail;
      const title = err?.response?.errors?.[0]?.title;

      let msg: string;
      if (detail) {
        msg = detail;
      } else if (title) {
        msg = title;
      } else if (!err?.response) {
        msg = extractText(app.translator.trans('ramon-verified.lib.errors.network'));
      } else {
        msg = extractText(app.translator.trans(opts.errorKey || 'ramon-verified.lib.errors.generic'));
      }

      app.alerts.show({ type: 'error' }, msg);
    }

    if (opts.rethrow) throw raw;
    return null;
  }
}
