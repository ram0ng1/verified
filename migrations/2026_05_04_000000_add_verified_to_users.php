<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('users', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }
            if (! $schema->hasColumn('users', 'verified_at')) {
                $table->dateTime('verified_at')->nullable();
            }
            if (! $schema->hasColumn('users', 'verified_by')) {
                $table->unsignedInteger('verified_by')->nullable();
            }
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('users', 'is_verified')) {
                $table->dropColumn('is_verified');
            }
            if ($schema->hasColumn('users', 'verified_at')) {
                $table->dropColumn('verified_at');
            }
            if ($schema->hasColumn('users', 'verified_by')) {
                $table->dropColumn('verified_by');
            }
        });
    },
];
