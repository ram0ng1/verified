import type User from 'flarum/common/models/User';

// Com o driver `username` o nome exibido é o próprio username, então o
// nickname (flarum/nicknames) ocupa a segunda linha no lugar do @handle.
export default function secondaryName(user: User | null | undefined): string | null {
  if (!user) return null;

  const displayName = user.displayName();
  const username = user.username();

  if (displayName === username) {
    const nickname = typeof user.verifiedNickname === 'function' ? user.verifiedNickname() : null;

    if (nickname && nickname !== displayName) return nickname;
  }

  return '@' + username;
}
