<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Services\UnitTreeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(UnitTreeService::class);

uses(TestCase::class, InteractsWithTestSetup::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    // Full chain: root -> mid -> leaf, plus an unrelated root.
    $this->root = Unit::create(['name' => 'ریشه']);
    $this->mid = Unit::create(['name' => 'میانی', 'parent_id' => $this->root->id]);
    $this->leaf = Unit::create(['name' => 'برگ', 'parent_id' => $this->mid->id]);
    $this->other = Unit::create(['name' => 'شاخه دیگر']);

    // A ZWNJ-bearing name — the folding case from issue #705.
    $this->professional = Unit::create([
        'name' => "حرفه\u{200C}ای",
        'parent_id' => $this->root->id,
    ]);
});

test('roots returns accessible units whose parent is not accessible', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $roots = app(UnitTreeService::class)->roots($ids);

    // Only the top of the accessible subtree is a root; mid/leaf are not,
    // because their parents ARE accessible.
    expect($roots->pluck('id')->all())->toBe([$this->root->id]);
});

test('roots includes a child whose parent is out of scope', function () {
    // Scope covers only the mid/leaf branch, not the root above it.
    $ids = Unit::descendantIds($this->mid->id)->toArray();

    $roots = app(UnitTreeService::class)->roots($ids);

    // mid's parent (root) is NOT in scope, so mid itself must render as a
    // root — otherwise a scoped user sees an empty tree.
    expect($roots->pluck('id')->all())->toBe([$this->mid->id]);
});

test('roots returns an empty collection for an empty scope', function () {
    expect(app(UnitTreeService::class)->roots([]))->toBeEmpty();
});

test('childrenOf returns only in-scope children with their unit type', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $children = app(UnitTreeService::class)->childrenOf($this->root->id, $ids);

    expect($children->pluck('id')->all())
        ->toContain($this->mid->id)
        ->toContain($this->professional->id)
        ->not->toContain($this->other->id);
});

test('childrenOf excludes children outside the scope', function () {
    // Scope = the leaf only. The leaf has no children at all.
    $ids = Unit::descendantIds($this->leaf->id)->toArray();

    expect(app(UnitTreeService::class)->childrenOf($this->root->id, $ids))->toBeEmpty();
});

test('search matches a name inside the scope', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $matches = app(UnitTreeService::class)->search('میانی', $ids);

    expect($matches->pluck('id')->all())->toBe([$this->mid->id]);
});

test('search folds ZWNJ so a half-spaced name is findable', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    // Stored name carries a ZWNJ: "حرفه‌ای" folds to "حرفه ای", so the
    // term must carry the space. This is the shape a user types after the
    // normalizer has rewritten their half-space input (issue #705).
    $matches = app(UnitTreeService::class)->search('حرفه ای', $ids);

    expect($matches->pluck('id')->all())->toContain($this->professional->id);
});

test('search folds Arabic alef variants', function () {
    // آموزش is stored with آ (U+0622) and must be findable when the user
    // types ا. The term is 5 characters so it clears the minimum.
    $alefUnit = Unit::create(['name' => 'آموزش', 'parent_id' => $this->root->id]);

    $ids = Unit::descendantIds($this->root->id)->toArray();

    $matches = app(UnitTreeService::class)->search('اموزش', $ids);

    expect($matches->pluck('id')->all())->toContain($alefUnit->id);
});

test('search never returns units outside the scope', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $matches = app(UnitTreeService::class)->search('شاخه دیگر', $ids);

    expect($matches)->toBeEmpty();
});

test('search returns nothing for a one-character term', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    // Floor is two CHARACTERS: "برگ" is right there, one letter must not hit it.
    expect(app(UnitTreeService::class)->search('ب', $ids))->toBeEmpty();
});

test('search matches a two-character term', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    // The old page gate was byte-based (strlen() > 2), which searched at two
    // Persian characters (4 bytes). The mb floor of 2 keeps that behaviour.
    $matches = app(UnitTreeService::class)->search('یا', $ids);

    expect($matches->pluck('name')->all())->toContain('میانی');
});

test('ancestorChain returns the full root to unit chain', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $chain = app(UnitTreeService::class)->ancestorChain($this->leaf, $ids);

    // Ordered root -> ... -> direct parent. The unit itself is excluded.
    expect($chain->pluck('id')->all())->toBe([$this->root->id, $this->mid->id]);
});

test('ancestorChain stops at the scope boundary', function () {
    // Scope starts at mid, so root is out of scope and must not appear.
    $ids = Unit::descendantIds($this->mid->id)->toArray();

    $chain = app(UnitTreeService::class)->ancestorChain($this->leaf, $ids);

    expect($chain->pluck('id')->all())->toBe([$this->mid->id]);
});

test('ancestorChain terminates on a parent_id cycle', function () {
    // A cycle must not hang the walk — the same guard the export's
    // buildHierarchy() uses.
    $a = Unit::create(['name' => 'الف']);
    $b = Unit::create(['name' => 'ب', 'parent_id' => $a->id]);
    $a->update(['parent_id' => $b->id]);

    $chain = app(UnitTreeService::class)->ancestorChain($a, [$a->id, $b->id]);

    expect($chain->pluck('id')->all())->toBe([$b->id]);
});
