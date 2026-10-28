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

use Illuminate\Console\Scheduling\Event;

class PurgeDocumentsSchedule
{
    public function __invoke(Event $event): void
    {
        $event->dailyAt('03:30');
    }
}
