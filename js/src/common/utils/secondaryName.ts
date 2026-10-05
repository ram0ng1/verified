import type User from "flarum/common/models/User";

/**
 * Segunda linha do popover, abaixo do nome exibido. Com o driver `username`
 * e um nickname disponível (flarum/nicknames ativo), mostra o nickname sem
 * prefixo; em todos os outros casos mostra o username como handle
 * (`@username`), inclusive quando ele repete o nome de cima.
 */
export default function secondaryName(
  user: User | null | undefined,
): string | null {
  if (!user) return null;

  const displayName = user.displayName();
  const username = user.username();

  if (displayName === username) {
    const nickname =
      typeof user.verifiedNickname === "function"
        ? user.verifiedNickname()
        : null;

    if (nickname && nickname !== displayName) return nickname;
  }

  return "@" + username;
}
