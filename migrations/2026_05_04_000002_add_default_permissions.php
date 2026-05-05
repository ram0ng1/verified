<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'verified.request' => Group::MEMBER_ID,
]);
