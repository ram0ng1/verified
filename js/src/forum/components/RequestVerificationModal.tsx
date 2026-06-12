import app from "flarum/forum/app";
import trustedHtml from "../../common/utils/trustedHtml";
import FormModal, { IFormModalAttrs } from "flarum/common/components/FormModal";
import Button from "flarum/common/components/Button";
import Form from "flarum/common/components/Form";
import Stream from "flarum/common/utils/Stream";
import extractText from "flarum/common/utils/extractText";
import type Mithril from "mithril";
import type MithrilStream from "mithril/stream";
import getBadgeSvg from "../../common/utils/getBadgeSvg";

interface DocumentType {
  id: string;
  label: string;
}

// Fallback list used only when the admin has cleared the configured list
// or the forum payload doesn't carry one. Mirrors the historical hardcoded
// defaults so existing forums see no behavioural change.
const FALLBACK_DOCUMENT_TYPES: DocumentType[] = [
  { id: "rg", label: "RG" },
  { id: "cpf", label: "CPF" },
  { id: "passport", label: "Passport" },
  { id: "driver", label: "Driver's license" },
  { id: "other", label: "Other" },
];

function getDocumentTypes(): DocumentType[] {
  const fromForum = app.forum.attribute<DocumentType[]>(
    "ramonVerifiedDocumentTypes",
  );
  if (Array.isArray(fromForum) && fromForum.length > 0) return fromForum;
  return FALLBACK_DOCUMENT_TYPES;
}

export default class RequestVerificationModal extends FormModal {
  protected reason!: MithrilStream<string>;
  protected documentType!: MithrilStream<string>;
  protected documentPath!: MithrilStream<string>;
  protected fileName!: MithrilStream<string>;
  protected uploading: boolean = false;
  protected uploadError: string | null = null;

  oninit(vnode: Mithril.Vnode<IFormModalAttrs, this>) {
    super.oninit(vnode);

    const types = getDocumentTypes();

    this.reason = Stream("");
    // Default to the first configured type. Admin may have reordered or
    // renamed `rg`, so we can't hardcode an id here.
    this.documentType = Stream(types[0] ? types[0].id : "");
    this.documentPath = Stream("");
    this.fileName = Stream("");
    this.uploading = false;
    this.uploadError = null;
  }

  className(): string {
    return "VerifiedRequestModal";
  }

  title(): Mithril.Children {
    return app.translator.trans("ramon-verified.forum.request_modal.title");
  }

  content(): Mithril.Children {
    const requireDoc = !!app.forum.attribute("ramonVerifiedRequireDocument");

    return [
      <div className="Modal-body VerifiedRequestModal-body">
        <div className="VerifiedRequestModal-hero">
          <div className="VerifiedRequestModal-hero-icon" aria-hidden="true">
            <span className="VerifiedRequestModal-hero-iconBadge">
              {trustedHtml(getBadgeSvg())}
            </span>
          </div>
          <h2 className="VerifiedRequestModal-hero-title">
            {app.translator.trans("ramon-verified.forum.request_modal.title")}
          </h2>
          <p className="VerifiedRequestModal-hero-text">
            {app.translator.trans("ramon-verified.forum.request_modal.intro")}
          </p>
        </div>

        <Form className="Form VerifiedRequestModal-form">
          <div className="Form-group VerifiedRequestModal-field">
            <label className="VerifiedRequestModal-fieldLabel">
              {app.translator.trans(
                "ramon-verified.forum.request_modal.reason_label",
              )}
            </label>
            <textarea
              className="FormControl VerifiedRequestModal-textarea"
              rows={4}
              maxlength={1000}
              placeholder={extractText(
                app.translator.trans(
                  "ramon-verified.forum.request_modal.reason_placeholder",
                ),
              )}
              bidi={this.reason}
              disabled={this.loading || this.uploading}
            />
          </div>

          {requireDoc ? this.documentFields() : null}
        </Form>
      </div>,

      <div className="VerifiedRequestModal-footer">
        <Button
          className="Button Button--primary VerifiedRequestModal-submit"
          type="submit"
          loading={this.loading}
          disabled={this.uploading || (requireDoc && !this.documentPath())}
        >
          {app.translator.trans(
            "ramon-verified.forum.request_modal.submit_button",
          )}
        </Button>
      </div>,
    ];
  }

  documentFields(): Mithril.Children {
    const disabled = this.loading || this.uploading;

    return [
      <div className="Form-group VerifiedRequestModal-field">
        <label className="VerifiedRequestModal-fieldLabel">
          {app.translator.trans(
            "ramon-verified.forum.request_modal.document_type_label",
          )}
        </label>
        <div className="VerifiedRequestModal-pillGroup" role="radiogroup">
          {getDocumentTypes().map((type) => {
            const active = this.documentType() === type.id;
            return (
              <button
                type="button"
                className={
                  "VerifiedRequestModal-pill" + (active ? " is-active" : "")
                }
                role="radio"
                aria-checked={active ? "true" : "false"}
                disabled={disabled}
                onclick={() => this.documentType(type.id)}
              >
                {type.label}
              </button>
            );
          })}
        </div>
      </div>,
      <div className="Form-group VerifiedRequestModal-field">
        <label className="VerifiedRequestModal-fieldLabel">
          {app.translator.trans(
            "ramon-verified.forum.request_modal.document_label",
          )}
        </label>
        {this.renderFileField()}
        <p className="VerifiedRequestModal-fieldHint">
          {app.translator.trans(
            "ramon-verified.forum.request_modal.document_help",
          )}
        </p>
      </div>,
    ];
  }

  renderFileField(): Mithril.Children {
    const disabled = this.loading || this.uploading;

    if (this.uploading) {
      return (
        <div className="VerifiedRequestModal-fileDrop is-uploading">
          <i
            className="icon fas fa-spinner fa-spin VerifiedRequestModal-fileDrop-icon"
            aria-hidden="true"
          />
          <span className="VerifiedRequestModal-fileDrop-title">
            {app.translator.trans(
              "ramon-verified.forum.request_modal.uploading",
            )}
          </span>
        </div>
      );
    }

    if (this.fileName() && this.documentPath()) {
      return (
        <div className="VerifiedRequestModal-fileSelected">
          <i
            className={
              "icon " +
              this.fileIcon(this.fileName()) +
              " VerifiedRequestModal-fileSelected-icon"
            }
            aria-hidden="true"
          />
          <span className="VerifiedRequestModal-fileSelected-info">
            <span className="VerifiedRequestModal-fileSelected-name">
              {this.fileName()}
            </span>
            <span className="VerifiedRequestModal-fileSelected-meta">
              {app.translator.trans(
                "ramon-verified.forum.request_modal.uploaded_short",
              )}
            </span>
          </span>
          <button
            type="button"
            className="Button Button--icon VerifiedRequestModal-fileSelected-remove"
            onclick={() => this.clearFile()}
            disabled={disabled}
            aria-label={extractText(
              app.translator.trans(
                "ramon-verified.forum.request_modal.remove_file",
              ),
            )}
          >
            <i className="icon fas fa-times" aria-hidden="true" />
          </button>
        </div>
      );
    }

    return [
      <label className="VerifiedRequestModal-fileDrop">
        <input
          type="file"
          className="VerifiedRequestModal-fileDrop-input"
          accept="image/png,image/jpeg,image/webp,application/pdf"
          onchange={(e: Event) => {
            const input = e.target as HTMLInputElement;
            this.uploadDocument(input.files && input.files[0]);
          }}
          disabled={disabled}
        />
        <i
          className="icon fas fa-cloud-arrow-up VerifiedRequestModal-fileDrop-icon"
          aria-hidden="true"
        />
        <span className="VerifiedRequestModal-fileDrop-title">
          {app.translator.trans(
            "ramon-verified.forum.request_modal.choose_file",
          )}
        </span>
        <span className="VerifiedRequestModal-fileDrop-hint">
          {app.translator.trans(
            "ramon-verified.forum.request_modal.choose_file_hint",
          )}
        </span>
      </label>,
      this.uploadError ? (
        <p className="helpText VerifiedRequestModal-error">
          {this.uploadError}
        </p>
      ) : null,
    ];
  }

  fileIcon(name: string): string {
    const ext = (name || "").split(".").pop()!.toLowerCase();
    if (ext === "pdf") return "fas fa-file-pdf";
    if (["png", "jpg", "jpeg", "webp", "gif"].indexOf(ext) !== -1)
      return "fas fa-file-image";
    return "fas fa-file";
  }

  clearFile(): void {
    this.fileName("");
    this.documentPath("");
    this.uploadError = null;
    m.redraw();
  }

  uploadDocument(file: File | null | undefined): void {
    if (!file) return;

    const MAX = 8 * 1024 * 1024;
    if (file.size > MAX) {
      this.uploadError = extractText(
        app.translator.trans(
          "ramon-verified.forum.request_modal.file_too_large",
        ),
      );
      m.redraw();
      return;
    }

    const allowed = [
      "image/png",
      "image/jpeg",
      "image/webp",
      "application/pdf",
    ];
    if (file.type && allowed.indexOf(file.type) === -1) {
      this.uploadError = extractText(
        app.translator.trans("ramon-verified.forum.request_modal.bad_type"),
      );
      m.redraw();
      return;
    }

    this.uploading = true;
    this.uploadError = null;
    this.documentPath("");
    this.fileName("");
    m.redraw();

    const body = new FormData();
    body.append("document", file);

    app
      .request<{ documentPath?: string }>({
        method: "POST",
        url: app.forum.attribute("apiUrl") + "/verified/documents",
        serialize: (raw: unknown) => raw,
        body,
      })
      .then((res) => {
        this.uploading = false;
        if (res && res.documentPath) {
          this.documentPath(res.documentPath);
          this.fileName(file.name || "");
        } else {
          this.uploadError = extractText(
            app.translator.trans(
              "ramon-verified.forum.request_modal.upload_failed",
            ),
          );
        }
        m.redraw();
      })
      .catch((err: { response?: { errors?: Array<{ detail?: string }> } }) => {
        this.uploading = false;
        const msg =
          (err &&
            err.response &&
            err.response.errors &&
            err.response.errors[0] &&
            err.response.errors[0].detail) ||
          extractText(
            app.translator.trans(
              "ramon-verified.forum.request_modal.upload_failed",
            ),
          );
        this.uploadError = msg;
        m.redraw();
      });
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();

    if (this.uploading) return;

    const requireDoc = !!app.forum.attribute("ramonVerifiedRequireDocument");
    if (requireDoc && !this.documentPath()) {
      this.uploadError = extractText(
        app.translator.trans(
          "ramon-verified.forum.request_modal.document_required",
        ),
      );
      m.redraw();
      return;
    }

    this.loading = true;

    const data: Record<string, string> = {
      reason: this.reason(),
    };

    if (this.documentPath()) {
      data.documentType = this.documentType();
      data.documentPath = this.documentPath();
    }

    app.store
      .createRecord("verification-requests")
      .save(data, { errorHandler: this.onerror.bind(this) })
      .then(() => {
        if (app.session.user) {
          app.session.user.pushAttributes({
            hasPendingVerificationRequest: true,
            canRequestVerification: false,
          });
        }
        app.alerts.show(
          { type: "success" },
          app.translator.trans("ramon-verified.forum.request_modal.success"),
        );
        this.hide();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
