<?php

namespace Ramon\Verified\Console;

use Flarum\Console\AbstractCommand;
use Ramon\Verified\Documents\DocumentRetention;
use Symfony\Component\Console\Command\Command;

/**
 * Purges verification documents older than the configured retention window.
 * No-op unless the retention mode is `delete_after_days`.
 *
 * Registered as a daily scheduled task via extend.php — admins can also
 * run it on demand: `php flarum verified:purge-documents`.
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
