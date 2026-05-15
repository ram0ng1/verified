<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Audit F2-index: compound index on `users(is_verified, verified_tier)`.
 *
 * `ListApprovedUsersController` now splits its tier-filter into a manual
 * SQL fast-path that hits `WHERE is_verified = 1 AND verified_tier = ?`
 * (with an `OR verified_tier IS NULL` arm for the default tier). Without
 * a compound index MySQL/MariaDB falls back to a single-column index — or
 * a full table scan when neither column is indexed (which is the case
 * here: `is_verified` and `verified_tier` ship as bare columns from the
 * earlier migrations 2026_05_04_000000 and 2026_05_07_000000).
 *
 * Leading column = `is_verified` for two reasons:
 *  - Every admin user-listing path filters on `is_verified` regardless of
 *    whether a tier filter is set, so the leading-edge of the index
 *    serves that lookup alone as well.
 *  - `is_verified` is boolean → low cardinality, but in this table the
 *    "verified" branch is the hot one and MySQL handles a 2-value leading
 *    column fine for range/equality scans.
 *
 * NB: per CLAUDE.md §45 we don't ADD columns to core tables — but the two
 * columns this index covers ALREADY exist (they shipped with this
 * extension's earlier migrations), so this is purely an INDEX add on the
 * already-extended schema. On MySQL 8 / MariaDB 10.6+ this runs as an
 * online (ALGORITHM=INPLACE, LOCK=NONE) operation; on older 5.7-class
 * MySQL it briefly metadata-locks `users` while the index builds. The
 * operation is fast (a single B-tree build over an existing column set)
 * but operators on giant `users` tables (millions of rows on legacy MySQL)
 * may want to schedule it.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('users')) {
            return;
        }
        if (! $schema->hasColumn('users', 'is_verified') || ! $schema->hasColumn('users', 'verified_tier')) {
            // Columns were never added (extension half-installed or rolled
            // back) — nothing to index. Earlier migrations would re-create
            // them on re-run; we just no-op here.
            return;
        }

        // Idempotent — skip if a previous run already created the index.
        // `getIndexes()` (Laravel 13) is portable across MySQL, MariaDB,
        // PostgreSQL and SQLite, so this doesn't break non-MySQL hosts.
        $indexes = collect($schema->getIndexes('users'));
        $alreadyExists = $indexes->contains(function ($index) {
            $cols = $index['columns'] ?? [];
            return is_array($cols)
                && count($cols) === 2
                && $cols[0] === 'is_verified'
                && $cols[1] === 'verified_tier';
        });
        if ($alreadyExists) {
            return;
        }

        $schema->table('users', function (Blueprint $table) {
            $table->index(['is_verified', 'verified_tier'], 'users_is_verified_verified_tier_index');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('users')) {
            return;
        }

        $indexes = collect($schema->getIndexes('users'));
        $exists = $indexes->contains(fn ($i) => ($i['name'] ?? null) === 'users_is_verified_verified_tier_index');
        if (! $exists) {
            return;
        }

        $schema->table('users', function (Blueprint $table) {
            $table->dropIndex('users_is_verified_verified_tier_index');
        });
    },
];
