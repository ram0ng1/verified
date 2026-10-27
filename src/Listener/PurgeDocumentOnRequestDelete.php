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

use Psr\Log\LoggerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

/**
 * Sem isto, um loop upload/submit/DELETE deixaria arquivos órfãos no disco. Uma
 * falha de I/O não bloqueia a remoção da linha; o sweep agendado limpa depois.
 */
class PurgeDocumentOnRequestDelete
{
    public function __construct(
        protected DocumentRetention $retention,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(VerificationRequest $model): void
    {
        try {
            $this->retention->purgeFileForRequest($model);
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to purge document on request delete', [
                'request_id' => (int) $model->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
