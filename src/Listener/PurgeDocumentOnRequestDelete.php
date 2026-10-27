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
 * Apaga o arquivo de documento quando a linha de `verification_requests` é
 * deletada. Sem este hook, um usuário com `verified.request` poderia repetir
 * upload → submit → DELETE em loop, deixando arquivos órfãos em disco.
 * Falhas de I/O são logadas mas não bloqueiam a remoção da linha — o sweep
 * agendado limpa órfãos posteriormente.
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
