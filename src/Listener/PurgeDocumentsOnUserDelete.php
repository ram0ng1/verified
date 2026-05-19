<?php

namespace Ramon\Verified\Listener;

use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Throwable;

/**
 * Apaga todos os documentos de um usuário sendo hard-deletado. Necessário
 * porque a FK `cascadeOnDelete` em `verification_requests.user_id` remove
 * as linhas no nível de DB e Eloquent não dispara eventos por linha — sem
 * este listener, os arquivos órfão.
 *
 * Registrado em `eloquent.deleting: User::class` apenas — este evento
 * dispara em TODOS os paths (`UserResource::deleting` da API + chamadas
 * diretas `$user->delete()` em tinker/CLI), tornando o domain-event
 * `UserDeleting` redundante.
 */
class PurgeDocumentsOnUserDelete
{
    public function __construct(
        protected DocumentRetention $retention,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(User $user): void
    {
        $userId = (int) $user->id;

        if ($userId <= 0) {
            return;
        }

        try {
            $this->retention->purgeAllForUser($userId);
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to purge documents on user delete', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
