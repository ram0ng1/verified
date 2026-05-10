/**
 * Wrap the textarea's current selection in `<tag>...</tag>` and return the
 * resulting string. If nothing is selected, the empty tag pair is inserted
 * at the caret so the user can type inside.
 *
 * Mutates the textarea in place: focuses it and restores the caret position
 * right after the inserted markup so typing keeps flowing. Returns the new
 * value so the caller can mirror it back into reactive state and Mithril
 * doesn't fight the redraw.
 */
export function wrapTextareaSelection(
  textarea: HTMLTextAreaElement,
  tag: 'strong' | 'em'
): string {
  const start = textarea.selectionStart ?? 0;
  const end = textarea.selectionEnd ?? 0;
  const value = textarea.value;
  const open = `<${tag}>`;
  const close = `</${tag}>`;

  const next = value.slice(0, start) + open + value.slice(start, end) + close + value.slice(end);

  textarea.value = next;
  textarea.focus();

  const caret = end === start
    ? start + open.length
    : end + open.length + close.length;
  textarea.setSelectionRange(caret, caret);

  return next;
}
