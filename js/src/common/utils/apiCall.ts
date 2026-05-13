import app from "flarum/common/app";
import extractText from "flarum/common/utils/extractText";
import type { FlarumRequestOptions } from "flarum/common/Application";

interface ApiCallOptions {
  /** Translation key for the error alert. Defaults to ramon-verified.lib.errors.generic. */
  errorKey?: string;
  /** Re-throw the error after surfacing it (so the caller can also branch on the failure). */
  rethrow?: boolean;
  /** Suppress the error alert entirely. The promise still resolves to null on failure. */
  silent?: boolean;
}

interface FlarumApiError {
  response?: {
    errors?: Array<{ detail?: string; title?: string; code?: string }>;
  };
  message?: string;
  status?: number;
}

/**
 * Wrap `app.request` with consistent error feedback.
 *
 * On success, returns the deserialised response (typed as T).
 * On failure, surfaces a user-visible alert and returns null — the caller can
 * branch on the null without writing the same .catch() boilerplate every time.
 *
 * The alert text prefers the API's first `errors[].detail`, falling back to
 * `errors[].title`, then to the configured translation key, then to the
 * generic "something went wrong" string. Network errors (no `response`) get
 * the dedicated `errors.network` translation.
 */
export default async function apiCall<T = unknown>(
  options: FlarumRequestOptions<T>,
  opts: ApiCallOptions = {}
): Promise<T | null> {
  try {
    return await app.request<T>(options);
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
        msg = extractText(
          app.translator.trans("ramon-verified.lib.errors.network")
        );
      } else {
        msg = extractText(
          app.translator.trans(
            opts.errorKey || "ramon-verified.lib.errors.generic"
          )
        );
      }

      app.alerts.show({ type: "error" }, msg);
    }

    if (opts.rethrow) throw raw;
    return null;
  }
}
