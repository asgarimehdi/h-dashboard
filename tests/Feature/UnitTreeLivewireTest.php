<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Services\AccessService;
use App\Services\UnitTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(UnitTreeService::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Issue #704 — the generic `unit.tree` component.
 *
 * These are the tree-mechanics tests that used to live in HrLivewireTest,
 * HrOrgChartCoverageTest and HrOrgNodeLivewireTest, plus the new contract
 * tests for badge views and the `unit-selected` event.
 */
beforeEach(function () {
    ['user' => $this->user, 'unit' => $this->root] = $this->createUserWithUnit();

    // root -> mid -> leaf
    $this->mid = Unit::create(['name' => 'میانی', 'parent_id' => $this->root->id]);
    $this->leaf = Unit::create(['name' => 'برگ', 'parent_id' => $this->mid->id]);
});

// ==================== Scope / roots ====================

test('renders only units inside the accessible scope', function () {
    $outsider = Unit::create(['name' => 'واحد بیرونی']);

    $roots = Livewire::actingAs($this->user)->test('unit.tree')->get('rootUnits');

    expect($roots->pluck('id')->all())->toBe([(int) $this->root->id])
        ->and($roots->pluck('id')->all())->not->toContain($outsider->id);
});

test('every rendered root belongs to the accessible scope', function () {
    $roots = Livewire::actingAs($this->user)->test('unit.tree')->get('rootUnits');
    $accessible = app(AccessService::class)->accessibleUnitIds();

    foreach ($roots as $unit) {
        expect($accessible)->toContain($unit->id);
    }
});

// ==================== Expansion ====================

test('default expansion covers the first three levels only', function () {
    $greatGrandchild = Unit::create(['name' => 'نوهٔ عمیق', 'parent_id' => $this->leaf->id]);

    $component = Livewire::actingAs($this->user)->test('unit.tree');
    $expanded = array_map('intval', $component->get('expanded'));

    expect($expanded)->toContain($this->root->id, $this->mid->id, $this->leaf->id)
        ->not->toContain($greatGrandchild->id);
});

test('toggle collapses an open unit and expands a closed one', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    // Root and mid are expanded by default (first 3 levels).
    expect($component->get('expanded'))->toContain((string) $this->mid->id);

    $component->call('toggle', (string) $this->mid->id);
    expect($component->get('expanded'))->not->toContain((string) $this->mid->id);

    $component->call('toggle', (string) $this->mid->id);
    expect($component->get('expanded'))->toContain((string) $this->mid->id)
        ->and($component->get('lazyChildren'))->toHaveKey($this->mid->id);
});

test('expandAll expands every root and collapseAll resets the tree', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree')->call('collapseAll');

    expect($component->get('expanded'))->toBe([])
        ->and($component->get('lazyChildren'))->toBe([]);

    $component->call('expandAll');

    expect($component->get('expanded'))->not->toBeEmpty()
        ->and($component->get('expanded'))->toContain((string) $this->root->id);
});

// ==================== Lazy loading ====================

test('loadChildren lazy-loads children for an expanded unit', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree')
        ->call('loadChildren', (int) $this->mid->id);

    expect($component->get('lazyChildren'))->toHaveKey($this->mid->id)
        ->and($component->get('lazyChildren')[$this->mid->id]->pluck('id'))
        ->toContain($this->leaf->id);
});

test('loadChildren ignores units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد بیرونی']);

    $component = Livewire::actingAs($this->user)->test('unit.tree')
        ->call('loadChildren', (int) $outsider->id);

    expect($component->get('lazyChildren'))->not->toHaveKey($outsider->id);
});

// ==================== Search ====================

test('search expands the full ancestor chain of deep matches', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree')->set('search', 'برگ');

    $expanded = array_map('intval', $component->get('expanded'));

    expect($expanded)->toContain($this->leaf->id)
        ->and($expanded)->toContain($this->mid->id)
        ->and($expanded)->toContain($this->root->id);
});

test('search clears previous expansion state first', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    expect($component->get('lazyChildren'))->not->toBeEmpty();

    // Short queries (<3 chars) skip matching but must still reset state.
    $component->set('search', 'ب');

    expect($component->get('lazyChildren'))->toBeEmpty()
        ->and($component->get('expanded'))->toBeEmpty();
});

test('search never expands units outside the scope', function () {
    $outsider = Unit::create(['name' => 'جستجوی ممنوع']);

    $component = Livewire::actingAs($this->user)->test('unit.tree')->set('search', 'ممنوع');

    expect(array_map('intval', $component->get('expanded')))->not->toContain($outsider->id);
});

// ==================== Reuse contract (#704) ====================

test('clicking a node dispatches unit-selected with the unit id', function () {
    Livewire::actingAs($this->user)->test('unit.tree')
        ->call('selectUnit', (int) $this->mid->id)
        ->assertDispatched('unit-selected', id: (int) $this->mid->id);
});

test('badge view renders the per-node data passed by the page', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree', [
        'badgeView' => 'livewire.hr.personnel-badge',
        'badgeData' => [(int) $this->root->id => 7],
    ]);

    // The unit with data shows its count…
    $component->assertSee('7 نفر');
    // …units without data fall back to 0 and get the vacancy badge.
    $component->assertSee('خالی');
});

test('badge data of zero renders the vacancy badge', function () {
    Livewire::actingAs($this->user)->test('unit.tree', [
        'badgeView' => 'livewire.hr.personnel-badge',
        'badgeData' => [(int) $this->root->id => 0],
    ])->assertSee('خالی');
});

test('tree renders without a badge view', function () {
    Livewire::actingAs($this->user)->test('unit.tree')
        ->assertSee($this->root->name)
        ->assertDontSee('نفر');
});

test('tree uses the search placeholder given by the page', function () {
    Livewire::actingAs($this->user)->test('unit.tree', ['searchPlaceholder' => 'جستجوی سفارشی'])
        ->assertSee('جستجوی سفارشی');
});

// ==================== Query count ====================

test('rendering the tree stays flat in queries as nodes are added', function () {
    // Ten extra leaves under one parent: a per-node query pattern would add
    // one query per node, a preloaded tree stays within a fixed budget.
    foreach (range(1, 10) as $i) {
        Unit::create(['name' => "برگ اضافه {$i}", 'parent_id' => $this->mid->id]);
    }

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (! Str::startsWith($query->sql, ['BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT'])) {
            $queries++;
        }
    });

    Livewire::actingAs($this->user)->test('unit.tree');

    expect($queries)->toBeLessThanOrEqual(10);
});
