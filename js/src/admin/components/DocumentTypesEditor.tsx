import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import DocumentTypesState from '../states/DocumentTypesState';

const trans = (key: string) => app.translator.trans(`ramon-verified.admin.${key}`);

/**
 * Editor for the configurable list of accepted document types.
 *
 * Each row is an `{ id, label }` pair (id used by the backend, label shown
 * to users). Saves are debounced via the bound state.
 */
export default class DocumentTypesEditor extends Component<ComponentAttrs> {
  protected types!: DocumentTypesState;

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    this.types = new DocumentTypesState();
  }

  view(): Mithril.Children {
    const rows = this.types.types;

    return (
      <div className="VerifiedAdmin-row VerifiedAdmin-types">
        <div className="VerifiedAdmin-types-header">
          <span className="VerifiedAdmin-types-headerId">{trans('settings.document_type_id_header')}</span>
          <span className="VerifiedAdmin-types-headerLabel">{trans('settings.document_type_label_header')}</span>
        </div>

        <div className="VerifiedAdmin-types-list">
          {rows.length === 0 ? (
            <p className="VerifiedAdmin-types-empty helpText">
              {trans('settings.document_types_empty')}
            </p>
          ) : (
            rows.map((row, idx) => (
              <div className="VerifiedAdmin-types-row" key={idx}>
                <input
                  type="text"
                  className="FormControl VerifiedAdmin-types-input VerifiedAdmin-types-id"
                  value={row.id}
                  placeholder="rg"
                  spellcheck="false"
                  autocomplete="off"
                  oninput={(e: Event) => this.types.update(idx, 'id', (e.target as HTMLInputElement).value)}
                />
                <input
                  type="text"
                  className="FormControl VerifiedAdmin-types-input VerifiedAdmin-types-label"
                  value={row.label}
                  placeholder={extractText(trans('settings.document_type_label_placeholder'))}
                  oninput={(e: Event) => this.types.update(idx, 'label', (e.target as HTMLInputElement).value)}
                />
                <button
                  type="button"
                  className="VerifiedAdmin-types-remove"
                  onclick={() => this.types.remove(idx)}
                  aria-label={extractText(trans('settings.document_type_remove'))}
                  title={extractText(trans('settings.document_type_remove'))}
                >
                  <i className="icon fas fa-times" />
                </button>
              </div>
            ))
          )}
        </div>

        <button
          type="button"
          className="VerifiedAdmin-types-add"
          onclick={() => this.types.add()}
        >
          <i className="icon fas fa-plus" />
          {trans('settings.document_type_add')}
        </button>
      </div>
    );
  }
}
