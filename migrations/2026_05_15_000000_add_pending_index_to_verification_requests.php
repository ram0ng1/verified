<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Audit M-index: add a compound index on `verification_requests(user_id, status)`.
 *
 * The original migration shipped two single-column indexes (`user_id` AND
 * `status`). That's the right shape when each column is queried in
 * isolation, but the hottest path through this extension is "do you have
 * any pending request?" — `WHERE user_id = ? AND status = 'pending'` —
 * which fires for every page render that includes the actor's own user
 * resource (the SettingsPage / popover / verify button gates all read
 * `hasPendingVerificationRequest`). It also fires once for every admin
 * user-listing row when the field getter loads its batch cache.
 *
 * MySQL/MariaDB picks ONE index per table per condition, so without a
 * compound it falls back to the more selective single-column index and
 * filters the rest in the buffer pool — fast on small tables, but as the
 * table grows (every request, including handled ones, lives here as audit
 * history) the cost creeps. The compound index makes the lookup an O(log n)
 * range scan on the leading edge of the index regardless of table size.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests')) {
            return;
        }

        // Idempotent — skip if a previous run already created the index.
        // `getIndexes()` is portable across MySQL / PostgreSQL / SQLite
        // in Laravel 13, so this doesn't break non-MySQL Flarum hosts.
        $indexes = collect($schema->getIndexes('verification_requests'));
        $alreadyExists = $indexes->contains(function ($index) {
            $cols = $index['columns'] ?? [];
            return is_array($cols)
                && count($cols) === 2
                && $cols[0] === 'user_id'
                && $cols[1] === 'status';
        });
        if ($alreadyExists) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'verification_requests_user_id_status_index');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests')) {
            return;
        }

        $indexes = collect($schema->getIndexes('verification_requests'));
        $exists = $indexes->contains(fn ($i) => ($i['name'] ?? null) === 'verification_requests_user_id_status_index');
        if (! $exists) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->dropIndex('verification_requests_user_id_status_index');
        });
    },
];
