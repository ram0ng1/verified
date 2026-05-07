<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Multi-tier badge support: a user can hold one of N tiers (blue / gold /
 * partner / …) defined by the admin in settings. Existing verified users get
 * mapped to the default `blue` tier so they keep their badge unchanged.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('users', 'verified_tier')) {
                $table->string('verified_tier', 40)->nullable()->after('verified_by');
            }
        });

        // Backfill: every previously verified user gets the default tier so
        // the badge keeps rendering through the new tier-aware code path.
        $schema->getConnection()
            ->table('users')
            ->where('is_verified', true)
            ->whereNull('verified_tier')
            ->update(['verified_tier' => 'blue']);
    },
    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('users', 'verified_tier')) {
                $table->dropColumn('verified_tier');
            }
        });
    },
];
