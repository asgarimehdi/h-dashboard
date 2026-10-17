<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/*
 * Pin for InteractsWithTestSetup::seedLookupTables() (issue #1044).
 *
 * 52 Feature tests used to seed the tahsils/estekhdams/semats/radifs lookup
 * tables by hand and skip the Postgres sequence resync, so a later
 * auto-increment insert collided with id=1. This locks the contract that
 * moved them onto the trait: the deterministic id=1 row exists, the sequence
 * is synced past it, and a repeated call is a no-op.
 */
uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

test('seedLookupTables seeds id=1 and resyncs the sequence so the next auto-increment insert does not collide', function () {
    $this->seedLookupTables();

    foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
        expect(DB::table($table)->where('id', 1)->exists())->toBeTrue();
    }

    // An explicit id=1 insert does not advance the sequence; without the
    // trait's setval this insert would collide on semats_id_seq.
    $nextId = DB::table('semats')->insertGetId(['name' => 'کارشناس ارشد']);

    expect($nextId)->toBeGreaterThan(1);
});

test('seedLookupTables is idempotent', function () {
    $this->seedLookupTables();
    $this->seedLookupTables();

    foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
        expect(DB::table($table)->where('id', 1)->count())->toBe(1);
    }
});
