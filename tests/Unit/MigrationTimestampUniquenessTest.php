<?php

use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Migration timestamp uniqueness (#1505)
|--------------------------------------------------------------------------
|
| Laravel orders migrations two ways and both key off the FILENAME, never the
| timestamp alone:
|
|   - `Migrator::getMigrationName()` (vendor/laravel/framework/src/Illuminate/
|     Database/Migrations/Migrator.php:608-610) takes the basename minus `.php`.
|   - `Migrator::getPendingMigrations()` (:155-156) diffs those full names
|     against the `migrations` table.
|
| So two files sharing one timestamp prefix are ordered only by the rest of the
| name, and a launcher that re-stamps one of them silently makes the other look
| pending forever. `2026_10_20_000001` was carried by both
| `add_file_path_index_to_attachments` and `add_folded_trgm_indexes_to_tickets_search`;
| the folded one is the idempotent migration (CREATE INDEX IF NOT EXISTS), so it
| is the one that moved to `000002` (#1505).
|
| The invariant is 17 characters — `YYYY_MM_DD_NNNNNN` — per the daily-counter
| convention in AGENTS.md. This test is the only thing pinning it: nothing else
| fails when a new pair collides.
|
*/

test('no two migrations share a timestamp prefix', function () {
    $prefixes = [];

    foreach (glob(database_path('migrations/*.php')) as $path) {
        $prefixes[substr(basename($path), 0, 17)][] = basename($path);
    }

    $duplicates = array_filter($prefixes, fn (array $files) => count($files) > 1);

    expect($duplicates)->toBe([]);
});
