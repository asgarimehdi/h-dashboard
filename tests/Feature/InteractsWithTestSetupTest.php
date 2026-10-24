<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
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

/*
 * Pin for InteractsWithTestSetup::assertQueryCount() (issue #1103).
 *
 * The helper used to listen with an arrow function, which captures by value,
 * so the listener wrote into a copy and every call counted 0 queries — the
 * guard could never fail. Three plain selects must exceed a ceiling of 0 and
 * 2 (AssertionFailedError with the pinned message) and pass at 3.
 */
test('assertQueryCount fails when the callback runs more queries than expected', function () {
    $runThreeQueries = function (): void {
        DB::select('select 1');
        DB::select('select 2');
        DB::select('select 3');
    };

    foreach ([0, 2] as $expected) {
        $failed = false;
        try {
            $this->assertQueryCount($expected, $runThreeQueries);
        } catch (AssertionFailedError $e) {
            $failed = true;
            expect($e->getMessage())->toContain("Expected ≤ {$expected} queries, got 3");
        }

        expect($failed)->toBeTrue(
            "assertQueryCount({$expected}) passed despite 3 queries — the guard is vacuous.",
        );
    }
});

test('assertQueryCount passes when the query count matches the ceiling', function () {
    $this->assertQueryCount(3, function (): void {
        DB::select('select 1');
        DB::select('select 2');
        DB::select('select 3');
    });
});

test('assertQueryCount ignores transaction statements inside the callback', function () {
    // RefreshDatabase already holds the outer transaction, so this nested
    // DB::transaction issues SAVEPOINT/COMMIT statements around the select.
    // Pinned so the call at UnitTreeServiceTest (expected=0) stays green.
    $this->assertQueryCount(1, function (): void {
        DB::transaction(function (): void {
            DB::select('select 1');
        });
    });
});
