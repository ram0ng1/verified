import app from "flarum/admin/app";
import Modal, { IInternalModalAttrs } from "flarum/common/components/Modal";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import type Mithril from "mithril";

export interface DocumentPreviewModalAttrs extends IInternalModalAttrs {
  /** Download URL: `/api/verified/documents/{id}` */
  url: string;
  /** Original filename or stored token (used to detect type). */
  filename?: string | null;
  /** Human-readable document type label. */
  typeLabel?: string | null;
}

const trans = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans(`ramon-verified.admin.requests.${key}`, params ?? {});

const IMAGE_EXTS = ["png", "jpg", "jpeg", "webp", "gif"];

function detectExtension(
  url: string | undefined,
  filename: string | undefined | null
): string {
  const candidate = filename || url || "";
  const stripped = String(candidate).split("?")[0].split("#")[0];
  const dotIdx = stripped.lastIndexOf(".");
  if (dotIdx === -1) return "";
  return stripped.slice(dotIdx + 1).toLowerCase();
}

/**
 * Inline preview for a verification document (image or PDF). Replaces the
 * old "open in new tab" link with a contained modal so reviewing happens
 * in-place without context-switching the admin's tabs.
 */
export default class DocumentPreviewModal extends Modal<DocumentPreviewModalAttrs> {
  protected imageLoaded: boolean = false;
  protected imageError: boolean = false;

  className(): string {
    return "DocumentPreviewModal";
  }

  title(): Mithril.Children {
    return trans("document_preview_title");
  }

  content(): Mithril.Children {
    const { url, filename, typeLabel } = this.attrs;
    const ext = detectExtension(url, filename);
    const isImage = IMAGE_EXTS.indexOf(ext) !== -1;
    const isPdf = ext === "pdf";

    return (
      <div className="Modal-body DocumentPreviewModal-body">
        {typeLabel ? (
          <div className="DocumentPreviewModal-meta">
            <span className="DocumentPreviewModal-meta-label">
              {trans("document_label")}
            </span>
            <span className="DocumentPreviewModal-meta-value">{typeLabel}</span>
          </div>
        ) : null}

        <div
          className={
            "DocumentPreviewModal-frame" +
            (isPdf ? " DocumentPreviewModal-frame--pdf" : "")
          }
        >
          {isImage ? this.renderImage(url) : null}
          {isPdf ? this.renderPdf(url) : null}
          {!isImage && !isPdf ? this.renderUnsupported() : null}
        </div>
      </div>
    );
  }

  renderImage(url: string): Mithril.Children {
    return [
      !this.imageLoaded && !this.imageError ? (
        <div className="DocumentPreviewModal-loading">
          <LoadingIndicator />
        </div>
      ) : null,

      this.imageError ? (
        <div className="DocumentPreviewModal-empty">
          <i className="icon fas fa-triangle-exclamation" />
          <span>{trans("preview_failed")}</span>
        </div>
      ) : (
        <img
          src={url}
          alt=""
          className={
            "DocumentPreviewModal-img" + (this.imageLoaded ? " is-loaded" : "")
          }
          onload={() => {
            this.imageLoaded = true;
            m.redraw();
          }}
          onerror={() => {
            this.imageError = true;
            m.redraw();
          }}
        />
      ),
    ];
  }

  renderPdf(url: string): Mithril.Children {
    return (
      <iframe
        src={url}
        className="DocumentPreviewModal-pdf"
        title={
          typeof trans("document_preview_title") === "string"
            ? (trans("document_preview_title") as unknown as string)
            : "Document preview"
        }
      />
    );
  }

  renderUnsupported(): Mithril.Children {
    return (
      <div className="DocumentPreviewModal-empty">
        <i className="icon fas fa-file" />
        <span>{trans("preview_unavailable")}</span>
      </div>
    );
  }
}
