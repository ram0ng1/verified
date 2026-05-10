<?php

use Illuminate\Database\Schema\Builder;

/**
 * Defensive cleanup: clear `verified_tier` rows for users that are no longer
 * verified (`is_verified=false`).
 *
 * Earlier versions of the runtime tier resolver treated `verified_tier` as
 * the source of truth even when `is_verified` was false — meaning a user
 * who was verified and then unverified through some non-standard path
 * (manual SQL, partial revoke flow, an older revoke bug) could remain
 * branded as verified forever.
 *
 * The resolver has since been tightened to require `is_verified=true` for
 * the manual path, but this migration normalises any stale rows already on
 * disk so the new logic agrees with the data immediately.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'verified_tier')) {
            return;
        }

        $schema->getConnection()
            ->table('users')
            ->where('is_verified', false)
            ->whereNotNull('verified_tier')
            ->update(['verified_tier' => null]);
    },
    'down' => function (Builder $schema) {
        // No-op: there is nothing meaningful to restore. The original tier
        // ids on unverified rows weren't a deliberate state.
    },
];
