// Module + global augmentations for the Verified extension.
//
// We extend Flarum core's User model with the new attributes we register via
// `Extend.Model(User).attribute(...)` in `common/extend.ts`. This is purely
// a type-level merge — runtime behaviour is owned by the extender.

import 'flarum/common/models/User';

declare module 'flarum/common/models/User' {
  export default interface User {
    isVerified(): boolean | undefined;
    verifiedAt(): Date | null | undefined;
    canRequestVerification(): boolean | undefined;
    hasPendingVerificationRequest(): boolean | undefined;
    isAvatarLocked(): boolean | undefined;
  }
}

// `flarum.reg` is provided by the runtime registry but isn't surfaced in
// core's typings. Avocado integration paths depend on it.
declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace flarum {
    const reg: {
      get(namespace: string, id: string): unknown;
      onLoad(namespace: string, id: string, callback: (mod: unknown) => void): void;
      addChunk?: unknown;
    };
  }
}

export {};
