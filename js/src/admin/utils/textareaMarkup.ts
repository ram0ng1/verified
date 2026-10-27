// Altera a textarea direto para manter o cursor; o valor devolvido deve ir
// para o estado, senão o redraw do Mithril o desfaz.
export function wrapTextareaSelection(textarea: HTMLTextAreaElement, tag: 'strong' | 'em'): string {
  const start = textarea.selectionStart ?? 0;
  const end = textarea.selectionEnd ?? 0;
  const value = textarea.value;
  const open = `<${tag}>`;
  const close = `</${tag}>`;

  const next = value.slice(0, start) + open + value.slice(start, end) + close + value.slice(end);

  textarea.value = next;
  textarea.focus();

  const caret = end === start ? start + open.length : end + open.length + close.length;
  textarea.setSelectionRange(caret, caret);

  return next;
}
