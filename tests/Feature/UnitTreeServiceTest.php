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

test('childrenOfMany groups children under their parent id', function () {
    $ids = Unit::descendantIds($this->root->id)->toArray();

    $grouped = app(UnitTreeService::class)->childrenOfMany([$this->root->id, $this->mid->id], $ids);

    expect($grouped->get($this->root->id)->pluck('id')->all())
        ->toContain($this->mid->id)
        ->toContain($this->professional->id)
        ->and($grouped->get($this->mid->id)->pluck('id')->all())
        ->toBe([$this->leaf->id]);
});

test('childrenOfMany filters children to the scope', function () {
    // Scope = the two top nodes only; leaf and professional are excluded.
    $grouped = app(UnitTreeService::class)->childrenOfMany(
        [$this->root->id, $this->mid->id],
        [$this->root->id, $this->mid->id],
    );

    // root keeps only its in-scope child, and mid — whose only child is out
    // of scope — gets no key at all (callers read with ->get(id, collect())).
    expect($grouped->get($this->root->id)->pluck('id')->all())->toBe([$this->mid->id])
        ->and($grouped->has($this->mid->id))->toBeFalse()
        ->and($grouped->flatten()->pluck('id')->all())
        ->not->toContain($this->leaf->id)
        ->not->toContain($this->professional->id);
});

test('childrenOfMany returns an empty collection for empty inputs without querying', function () {
    $this->assertQueryCount(0, function (): void {
        expect(app(UnitTreeService::class)->childrenOfMany([], [$this->root->id]))->toBeEmpty()
            ->and(app(UnitTreeService::class)->childrenOfMany([$this->root->id], []))->toBeEmpty();
    });
});

test('childrenOfMany yields nothing for an out-of-scope parent', function () {
    // Scope starts at mid, so root is invisible to this caller. Asking for
    // root's children must answer exactly like childrenOf(root, scope) does.
    $ids = Unit::descendantIds($this->mid->id)->toArray();

    expect(app(UnitTreeService::class)->childrenOfMany([$this->root->id], $ids))->toBeEmpty()
        ->and(app(UnitTreeService::class)->childrenOf($this->root->id, $ids))->toBeEmpty();
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

test('search eager-loads six ancestor hops so the chain walk stays query-free', function () {
    // Six hops is the deepest chain in dev (#1285). A seven-unit chain puts
    // every ancestor of the match behind `with('parent.parent…')`, so a
    // query-free ancestorChain() proves the loader — not the walker — paid
    // for the chain. The walk itself must stay untouched: PersonsExport
    // depends on it never querying.
    $scope = [];
    $parentId = null;

    for ($i = 0; $i <= 6; $i++) {
        $unit = Unit::create([
            'name' => "زنجیره جستجو {$i}",
            'parent_id' => $parentId,
        ]);
        $scope[] = $unit->id;
        $parentId = $unit->id;
    }

    $service = app(UnitTreeService::class);
    $matches = collect();

    // 1 units query + 6 parent hops. unit_type_id is null on every fixture
    // unit, so the unitType eager never fires a query of its own. Measured
    // (not assumed): 7 queries after the fix.
    $this->assertQueryCount(7, function () use ($service, $scope, &$matches): void {
        $matches = $service->search('زنجیره جستجو 6', $scope);
    });

    expect($matches)->toHaveCount(1);

    // The discriminator: before the eager-load fix this walk lazily loaded
    // five more parents. It must cost zero queries now. Walk order is root
    // first, so the chain is u0..u5 — the match (u6) is excluded.
    $this->assertQueryCount(0, function () use ($service, $matches, $scope): void {
        $chain = $service->ancestorChain($matches->firstOrFail(), $scope);

        expect($chain->pluck('id')->all())->toBe(array_slice($scope, 0, 6));
    });
});

test('a seventh ancestor hop is the documented lazy fallback', function () {
    // The eager-load is exactly six hops deep. With an eight-unit chain the
    // seventh hop (u0) stays lazy: search pays one extra query, and the
    // chain walk pays exactly one. This is the boundary the constant in
    // UnitTreeService::search() documents — not a second code path.
    $scope = [];
    $parentId = null;

    for ($i = 0; $i <= 7; $i++) {
        $unit = Unit::create([
            'name' => "مرز شش {$i}",
            'parent_id' => $parentId,
        ]);
        $scope[] = $unit->id;
        $parentId = $unit->id;
    }

    $service = app(UnitTreeService::class);
    $matches = collect();

    // 1 units + 6 eager parent hops (the eager cannot go deeper), then the
    // walk's single lazy hop.
    $this->assertQueryCount(7, function () use ($service, $scope, &$matches): void {
        $matches = $service->search('مرز شش 7', $scope);
    });

    $this->assertQueryCount(1, function () use ($service, $matches, $scope): void {
        $chain = $service->ancestorChain($matches->firstOrFail(), $scope);

        expect($chain->pluck('id')->all())->toBe(array_slice($scope, 0, 7));
    });
});

test('ancestorChain stops before an out-of-scope parent without querying', function () {
    $root = Unit::create(['name' => 'ریشه پنهان']);
    $mid = Unit::create(['name' => 'میانی مرزی', 'parent_id' => $root->id]);
    $leaf = Unit::create(['name' => 'برگ مرزی', 'parent_id' => $mid->id]);

    // A FRESH model: a match returned by search() already has its parent
    // eager-loaded, which would make this assertion pass for the wrong
    // reason (gate correction 5).
    $fresh = Unit::query()->findOrFail($leaf->id);

    $this->assertQueryCount(0, function () use ($fresh, $leaf): void {
        $chain = app(UnitTreeService::class)->ancestorChain($fresh, [$leaf->id]);

        expect($chain)->toBeEmpty()
            // The scope check at line 213 runs before the relation access at
            // line 217, so the out-of-scope parent is never loaded at all.
            ->and($fresh->relationLoaded('parent'))->toBeFalse();
    });
});

test('ancestorChain loads only the in-scope parent and never crosses the boundary', function () {
    $root = Unit::create(['name' => 'ریشه پنهان']);
    $mid = Unit::create(['name' => 'میانی مرزی', 'parent_id' => $root->id]);
    $leaf = Unit::create(['name' => 'برگ مرزی', 'parent_id' => $mid->id]);

    $fresh = Unit::query()->findOrFail($leaf->id);

    // One query: the in-scope mid. Root is out of scope, so the walk breaks
    // before touching mid->parent.
    $this->assertQueryCount(1, function () use ($fresh, $mid, $leaf): void {
        $chain = app(UnitTreeService::class)->ancestorChain($fresh, [$mid->id, $leaf->id]);

        expect($chain->pluck('id')->all())->toBe([$mid->id])
            ->and($chain->first()->relationLoaded('parent'))->toBeFalse();
    });
});
