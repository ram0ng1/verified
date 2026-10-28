<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * No-op mantida porque o nome já está registrado em `migrations` nos fóruns que
 * a rodaram. O índice pretendido agora vive em `user_verification`
 * (2026_05_18_000001).
 */
return [
    'up' => fn () => null,
    'down' => fn () => null,
];
