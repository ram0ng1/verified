<?php

namespace Ramon\Verified\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Verified\Documents\DocumentRetention;
use Symfony\Component\Console\Command\Command;

/**
 * Apaga documentos de verificação fora da janela de retenção configurada.
 * No-op fora do modo `delete_after_days`. Agendado diariamente via
 * `extend.php`; admins também podem rodar manualmente via
 * `php flarum verified:purge-documents`.
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
