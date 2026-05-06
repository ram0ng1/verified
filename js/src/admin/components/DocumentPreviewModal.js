import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Inline preview for a verification document (image or PDF). Replaces the
 * old "open in new tab" link with a contained modal so reviewing happens
 * in-place without context-switching the admin's tabs.
 *
 * Receives via attrs:
 *   - url       : the /api/verified/documents/{id} download URL
 *   - filename  : original filename or stored token (used to detect type)
 *   - typeLabel : human-readable document type (e.g. "Driver's license")
 *
 * The file type is detected from the URL/filename extension. Images render
 * inline via <img>; PDFs via <iframe> (every evergreen browser handles PDF
 * embedding natively). Anything else falls back to a "preview unavailable"
 * card with a direct download link.
 */
const trans = (key, params) => app.translator.trans(`ramon-verified.admin.requests.${key}`, params);

const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

function detectExtension(url, filename) {
  const candidate = filename || url || '';
  const stripped = String(candidate).split('?')[0].split('#')[0];
  const dotIdx = stripped.lastIndexOf('.');
  if (dotIdx === -1) return '';
  return stripped.slice(dotIdx + 1).toLowerCase();
}

export default class DocumentPreviewModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.imageLoaded = false;
    this.imageError = false;
  }

  className() {
    return 'DocumentPreviewModal';
  }

  title() {
    return trans('document_preview_title');
  }

  content() {
    const { url, filename, typeLabel } = this.attrs;
    const ext = detectExtension(url, filename);
    const isImage = IMAGE_EXTS.indexOf(ext) !== -1;
    const isPdf   = ext === 'pdf';

    return (
      <div className="Modal-body DocumentPreviewModal-body">
        {typeLabel ? (
          <div className="DocumentPreviewModal-meta">
            <span className="DocumentPreviewModal-meta-label">
              {trans('document_label')}
            </span>
            <span className="DocumentPreviewModal-meta-value">{typeLabel}</span>
          </div>
        ) : null}

        <div className={'DocumentPreviewModal-frame' + (isPdf ? ' DocumentPreviewModal-frame--pdf' : '')}>
          {isImage ? this.renderImage(url) : null}
          {isPdf ? this.renderPdf(url) : null}
          {!isImage && !isPdf ? this.renderUnsupported() : null}
        </div>
      </div>
    );
  }

  renderImage(url) {
    return [
      !this.imageLoaded && !this.imageError ? (
        <div className="DocumentPreviewModal-loading">
          <LoadingIndicator />
        </div>
      ) : null,

      this.imageError ? (
        <div className="DocumentPreviewModal-empty">
          <i className="icon fas fa-triangle-exclamation" />
          <span>{trans('preview_failed')}</span>
        </div>
      ) : (
        <img
          src={url}
          alt=""
          className={'DocumentPreviewModal-img' + (this.imageLoaded ? ' is-loaded' : '')}
          onload={() => { this.imageLoaded = true; m.redraw(); }}
          onerror={() => { this.imageError = true; m.redraw(); }}
        />
      ),
    ];
  }

  renderPdf(url) {
    // Browsers render PDFs natively in <iframe>. The download URL is the
    // same — disposition header makes it inline by default.
    return (
      <iframe
        src={url}
        className="DocumentPreviewModal-pdf"
        title={trans('document_preview_title')}
      />
    );
  }

  renderUnsupported() {
    return (
      <div className="DocumentPreviewModal-empty">
        <i className="icon fas fa-file" />
        <span>{trans('preview_unavailable')}</span>
      </div>
    );
  }
}
