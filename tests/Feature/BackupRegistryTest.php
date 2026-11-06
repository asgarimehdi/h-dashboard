<?php

use App\Support\Backup\TableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The registry is the contract of the whole feature (issue #1375): the backup
 * touches these tables and nothing else, and the FK graph decides the order
 * tables are restored in. Both are asserted against the LIVE database, not
 * against a hand-written list — the graph is built from information_schema so
 * later alter-migrations cannot drift away from it.
 */
test('registry holds exactly the 32 business tables', function () {
    $tables = app(TableRegistry::class)->tables();

    expect($tables)->toHaveCount(32);
    expect($tables)->toContain('users', 'persons', 'units', 'tickets');
});

test('registry excludes the table that only exists in the issue body', function () {
    // The issue's list says 33 tables and names `hardware_histories`; no
    // migration creates it (the migrate-to-audits migration DROPS it), so it
    // must never be in the registry.
    expect(app(TableRegistry::class)->tables())->not->toContain('hardware_histories');
});

test('registry excludes system spatie and postgis tables', function () {
    $tables = app(TableRegistry::class)->tables();

    $forbidden = [
        // system tables
        'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches',
        'failed_jobs', 'password_reset_tokens', 'personal_access_tokens',
        'migrations',
        // Spatie permission tables
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles',
        'role_has_permissions',
        // PostGIS / ledger
        'spatial_ref_sys', 'geometry_columns', 'geography_columns',
    ];

    foreach ($forbidden as $table) {
        expect($tables)->not->toContain($table);
    }
});

test('every registered table exists in the live database', function () {
    $existing = array_map(
        fn ($row) => $row->table_name,
        DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()')
    );

    foreach (app(TableRegistry::class)->tables() as $table) {
        expect($existing)->toContain($table);
    }
});

test('foreign key graph carries the referenced column not just the table', function () {
    $edges = app(TableRegistry::class)->foreignKeys();

    // `users.n_code` points at `persons.n_code` — NOT at `persons.id`.
    $userEdge = collect($edges)->first(
        fn ($edge) => $edge->childTable === 'users' && $edge->childColumn === 'n_code'
    );

    expect($userEdge)->not->toBeNull();
    expect($userEdge->parentTable)->toBe('persons');
    expect($userEdge->parentColumn)->toBe('n_code');
    expect($userEdge->onDelete)->toBe('RESTRICT');
});

test('foreign key graph only relates registered tables', function () {
    $tables = app(TableRegistry::class)->tables();

    foreach (app(TableRegistry::class)->foreignKeys() as $edge) {
        expect($tables)->toContain($edge->childTable);
        expect($tables)->toContain($edge->parentTable);
    }
});

test('topological order puts parents before children', function () {
    $order = app(TableRegistry::class)->topologicalOrder();

    expect($order)->toHaveCount(32);

    $position = fn (string $table) => array_search($table, $order, true);

    expect($position('boundaries'))->toBeLessThan($position('units'));
    expect($position('units'))->toBeLessThan($position('persons'));
    expect($position('persons'))->toBeLessThan($position('users'));
    expect($position('tickets'))->toBeLessThan($position('attachments'));
    expect($position('ticket_comments'))->toBeLessThan($position('ticket_comment_reactions'));
});
