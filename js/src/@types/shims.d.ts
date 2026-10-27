import 'flarum/common/models/User';
import 'flarum/common/Store';

declare module 'flarum/common/models/User' {
  export default interface User {
    isVerified(): boolean | undefined;
    verifiedAt(): Date | null | undefined;
    verifiedTier(): string | null | undefined;
    verifiedNickname(): string | null | undefined;
    canRequestVerification(): boolean | undefined;
    hasPendingVerificationRequest(): boolean | undefined;
    isAvatarLocked(): boolean | undefined;
  }
}

declare module 'flarum/common/Store' {
  interface JsonApiResource {
    type: string;
    id: string | number;
  }
  interface JsonApiPayload {
    data: JsonApiResource | JsonApiResource[];
    included?: JsonApiResource[];
    meta?: { page?: { total?: number } } & Record<string, unknown>;
  }
  export default interface Store {
    pushPayload(payload: JsonApiPayload): unknown;
  }
}

// Existe em runtime, mas as typings do core não o declaram.
declare global {
  namespace flarum {
    const reg: {
      get(namespace: string, id: string): unknown;
      onLoad(namespace: string, id: string, callback: (mod: unknown) => void): void;
      addChunk?: unknown;
    };
  }

  // Substituído pelo DefinePlugin do webpack no build; sem @types/node.
  const process: {
    env: {
      NODE_ENV?: string;
      [key: string]: string | undefined;
    };
  };
}

export {};
