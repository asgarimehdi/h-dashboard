<?php

namespace Tests\Unit;

use App\Support\UnitAncestry;
use Tests\TestCase;

covers(UnitAncestry::class);

uses(TestCase::class);

/**
 * The exact shape `UnitsExportController::hierarchyMap()` and
 * `PersonsExport::unitsById()` build: id => name/parent_id.
 *
 * @return array<int, array{name: string, parent_id: int|null}>
 */
function ancestryFixture(): array
{
    return [
        1 => ['name' => 'ریشه', 'parent_id' => null],
        2 => ['name' => 'میانی', 'parent_id' => 1],
        3 => ['name' => 'برگ', 'parent_id' => 2],
        4 => ['name' => 'برگ‌تر', 'parent_id' => 3],
    ];
}

test('chain returns root-first ancestors and excludes the unit', function () {
    $chain = UnitAncestry::chain(4, ancestryFixture(), null);

    expect(array_column($chain, 'id'))->toBe([1, 2, 3])
        ->and(array_column($chain, 'name'))->toBe(['ریشه', 'میانی', 'برگ']);
});

test('chain returns an empty list for a root unit', function () {
    expect(UnitAncestry::chain(1, ancestryFixture(), null))->toBe([]);
});

test('chain stops before an out-of-scope parent without naming it', function () {
    // Scope starts at the leaf: the mid unit is the boundary, the root is
    // never named — same contract as UnitTreeService::ancestorChain().
    $chain = UnitAncestry::chain(4, ancestryFixture(), [4, 3]);

    expect($chain)->toBe([['id' => 3, 'name' => 'برگ']]);
});

test('chain treats a null scope as unlimited', function () {
    expect(UnitAncestry::chain(4, ancestryFixture(), null))->toHaveCount(3);
});

test('chain treats an empty scope as no ancestors', function () {
    expect(UnitAncestry::chain(4, ancestryFixture(), []))->toBe([]);
});

test('chain terminates on a two-node cycle', function () {
    $index = [
        1 => ['name' => 'الف', 'parent_id' => 2],
        2 => ['name' => 'ب', 'parent_id' => 1],
    ];

    // Seeding visited with the unit itself is what stops A→B→A here.
    expect(UnitAncestry::chain(1, $index, null))->toBe([['id' => 2, 'name' => 'ب']])
        ->and(UnitAncestry::chain(2, $index, null))->toBe([['id' => 1, 'name' => 'الف']]);
});

test('chain terminates on a self-parent', function () {
    $index = [7 => ['name' => 'خودوالد', 'parent_id' => 7]];

    expect(UnitAncestry::chain(7, $index, null))->toBe([]);
});

test('chain stops when a parent id is missing from the index', function () {
    $index = [9 => ['name' => 'یتیم', 'parent_id' => 404]];

    expect(UnitAncestry::chain(9, $index, null))->toBe([]);
});

test('chain stops at the depth cap', function () {
    $index = [
        1 => ['name' => 'سطح ۰', 'parent_id' => null],
        2 => ['name' => 'سطح ۱', 'parent_id' => 1],
        3 => ['name' => 'سطح ۲', 'parent_id' => 2],
        4 => ['name' => 'سطح ۳', 'parent_id' => 3],
        5 => ['name' => 'سطح ۴', 'parent_id' => 4],
    ];

    // Nearest ancestors first, then root-first in the output: depth 2 keeps
    // levels 2 and 3 and drops the ones above the cap.
    expect(array_column(UnitAncestry::chain(5, $index, null, 2), 'id'))->toBe([3, 4])
        ->and(UnitAncestry::chain(5, $index, null, 0))->toBe([])
        // The default cap never truncates a normal chain.
        ->and(UnitAncestry::chain(5, $index, null))->toHaveCount(4)
        ->and(UnitAncestry::MAX_DEPTH)->toBe(100);
});

test('chain preserves the exact order of a deep chain', function () {
    $index = [];
    $parentId = null;

    for ($i = 1; $i <= 6; $i++) {
        $index[$i] = ['name' => "واحد {$i}", 'parent_id' => $parentId];
        $parentId = $i;
    }

    expect(array_column(UnitAncestry::chain(6, $index, null), 'id'))->toBe([1, 2, 3, 4, 5]);
});

test('chain does not mutate the index', function () {
    $index = ancestryFixture();
    $before = ancestryFixture();

    UnitAncestry::chain(4, $index, null);
    UnitAncestry::chain(4, $index, [4, 3]);

    expect($index)->toBe($before);
});
