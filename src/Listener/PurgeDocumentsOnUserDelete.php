<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Listener;

use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Throwable;

/**
 * O `cascadeOnDelete` da FK apaga as solicitações sem disparar eventos Eloquent,
 * o que deixaria os arquivos órfãos. `eloquent.deleting` cobre tanto a API quanto
 * um `$user->delete()` direto.
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
