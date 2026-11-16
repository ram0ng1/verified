import app from 'flarum/forum/app';

let starting = false;

// Abre (ou retoma) a sessão da Didit e leva o usuário até ela. Os erros da API
// (pedidos fechados, Didit fora do ar) aparecem no alerta padrão do core.
export default function startDidit(): void {
  if (starting) return;
  starting = true;
  m.redraw();

  app
    .request<{ url: string }>({
      method: 'POST',
      url: `${app.forum.attribute<string>('apiUrl')}/verified/didit/session`,
      body: {},
    })
    .then((res) => {
      // Só a URL da própria Didit: o valor vem do servidor, mas não abre outro destino.
      if (res && /^https:\/\/[a-z0-9.-]+\.didit\.me\//i.test(res.url)) window.location.href = res.url;
      else starting = false;
    })
    .catch(() => {
      starting = false;
    })
    .finally(() => m.redraw());
}

export const isStartingDidit = () => starting;
