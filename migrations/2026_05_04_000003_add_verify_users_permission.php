<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'verified.verifyUsers' => Group::ADMINISTRATOR_ID,
]);
