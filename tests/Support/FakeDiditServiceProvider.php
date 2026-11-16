<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Tests\Support;

use Flarum\Foundation\AbstractServiceProvider;
use Ramon\Verified\Didit\DiditClient;

class FakeDiditServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(DiditClient::class, FakeDiditClient::class);
    }
}
