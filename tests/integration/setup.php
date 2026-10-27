<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

// O cache do catálogo de traduções grava aqui; sem a pasta, cada teste que
// traduz algo dispara um warning.
@mkdir(__DIR__.'/../../vendor/flarum/testing/src/integration/tmp/storage/locale', 0777, true);
