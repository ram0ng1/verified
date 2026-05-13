import app from "flarum/common/app";
import extractText from "flarum/common/utils/extractText";

let installed = false;

interface FlarumApiError {
  response?: { errors?: Array<{ detail?: string; title?: string }> };
  alreadyHandled?: boolean;
}

/**
 * Last-resort handler for promise rejections that escape every `.catch()`
 * in the verified bundle. Without this, a missed rejection in admin/forum
 * code logs to the browser console only and the user sees nothing.
 *
 * Errors that already carry a Flarum API response are skipped — those are
 * surfaced through `apiCall` (or core's own `errorHandler`), and showing a
 * second alert would be noise.
 *
 * Idempotent across both forum and admin bundles loading on the same page.
 */
export default function installGlobalErrorHandler(): void {
  if (installed || typeof window === "undefined") return;
  installed = true;

  window.addEventListener("unhandledrejection", (event) => {
    const reason = event.reason as FlarumApiError | undefined;
    if (reason && (reason.alreadyHandled || reason.response)) return;

    if (!app || !app.alerts || !app.translator) return;
    app.alerts.show(
      { type: "error" },
      extractText(app.translator.trans("ramon-verified.lib.errors.generic")),
    );
  });
}
