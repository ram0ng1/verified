<?php

use Flarum\Database\Migration;

return Migration::addColumns('users', [
    'is_verified' => ['boolean', 'default' => false],
    'verified_at' => ['dateTime', 'nullable' => true],
    'verified_by' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
