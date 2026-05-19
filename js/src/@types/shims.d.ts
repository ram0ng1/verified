/**
 * Module + global augmentations for the Verified extension.
 *
 * Estende o User model do Flarum core com os atributos registrados via
 * `Extend.Model(User).attribute(...)` em `common/extend.ts`. Merge puramente
 * de tipos — runtime é controlado pelo extender.
 *
 * `Store.pushPayload` é aumentada para aceitar o shape JSON:API que
 * `VerificationRequestsState.load` envia, em vez de `as any`.
 */

import "flarum/common/models/User";
import "flarum/common/Store";

declare module "flarum/common/models/User" {
  export default interface User {
    isVerified(): boolean | undefined;
    verifiedAt(): Date | null | undefined;
    verifiedTier(): string | null | undefined;
    canRequestVerification(): boolean | undefined;
    hasPendingVerificationRequest(): boolean | undefined;
    isAvatarLocked(): boolean | undefined;
  }
}

declare module "flarum/common/Store" {
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

/**
 * `flarum.reg` é fornecido pelo runtime registry mas não exposto nas
 * typings do core. Paths de integração com o Avocado dependem dele.
 */
declare global {
  namespace flarum {
    const reg: {
      get(namespace: string, id: string): unknown;
      onLoad(
        namespace: string,
        id: string,
        callback: (mod: unknown) => void,
      ): void;
      addChunk?: unknown;
    };
  }

  /**
   * Webpack injeta `process.env.NODE_ENV` em build via DefinePlugin
   * (`'production'` / `'development'`). Sem `@types/node` instalado,
   * declaramos só o subset que usamos para o gate dev-warn (§40.2).
   */
  const process: {
    env: {
      NODE_ENV?: string;
      [key: string]: string | undefined;
    };
  };
}

export {};
