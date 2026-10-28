<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Verified\Documents\DocumentRetention;
use Symfony\Component\Console\Command\Command;

/**
 * Apaga documentos fora da janela de retenção (só no modo `delete_after_days`).
 * Agendado diariamente.
 */
class PurgeDocumentsCommand extends AbstractCommand
{
    public function __construct(
        protected DocumentRetention $retention
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('verified:purge-documents')
            ->setDescription('Purge verification documents older than the configured retention window.');
    }

    protected function fire(): int
    {
        // Uploads que nunca viraram solicitação: valem em qualquer modo.
        $orphans = $this->retention->sweepAllOrphans();
        $this->info("{$orphans} unattached upload(s) removed.");

        $mode = $this->retention->mode();

        if ($mode !== DocumentRetention::MODE_DELETE_AFTER_DAYS) {
            $this->info("Document retention mode is \"{$mode}\" — nothing to do.");
            return Command::SUCCESS;
        }

        $days = $this->retention->retentionDays();
        $this->info("Purging documents older than {$days} day(s)…");

        $purged = $this->retention->purgeExpired();

        $this->info("Done. {$purged} request document(s) purged.");

        return Command::SUCCESS;
    }
}
