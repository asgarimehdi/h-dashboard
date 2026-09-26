<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HrAnalyticsController;
use App\Http\Controllers\Api\HrStatsController;
use App\Http\Controllers\Api\OrgChartController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitTreeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

covers(OrgChartController::class, HrStatsController::class, HrAnalyticsController::class);

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): the tree-mechanics guard rails — lazy loading, the search
// reset path, ancestor-chain expansion, cycle guards, and the scope roots — now
// live in the generic `unit.tree` component + UnitTreeService (issue #704),
// so they are exercised against `unit.tree` rather than `hr.org-chart`.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    // Tree: root -> mid -> leaf
    $this->root = Unit::create(['name' => 'ریشه']);
    $this->mid = Unit::create(['name' => 'میانی', 'parent_id' => $this->root->id]);
    $this->leaf = Unit::create(['name' => 'برگ', 'parent_id' => $this->mid->id]);

    $this->user = User::factory()->create();
    $this->user->units()->attach($this->root->id, ['role' => 'responsible', 'is_primary' => true]);
    $this->user->givePermissionTo('view_hr_dashboard');

    Person::create([
        'n_code' => '1111111111', 'f_name' => 'علی', 'l_name' => 'رضایی',
        'u_id' => $this->leaf->id, 's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
});

test('loadChildren lazy-loads children for an expanded unit', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    $component->call('loadChildren', $this->mid->id);

    expect($component->instance()->lazyChildren)->toHaveKey($this->mid->id)
        ->and($component->instance()->lazyChildren[$this->mid->id]->pluck('id'))
        ->toContain($this->leaf->id);
});

test('loadChildren ignores units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد بیرونی']);

    $component = Livewire::actingAs($this->user)->test('unit.tree')
        ->call('loadChildren', $outsider->id);

    expect($component->instance()->lazyChildren)->not->toHaveKey($outsider->id);
});

test('updatedSearch expands full ancestor chain of deep matches', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    $component->set('search', 'برگ');

    $expanded = array_map('intval', $component->instance()->expanded);

    expect($expanded)->toContain($this->leaf->id)
        ->and($expanded)->toContain($this->mid->id)
        ->and($expanded)->toContain($this->root->id);
});

test('updatedSearch clears previous expansion state first', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    expect($component->instance()->lazyChildren)->not->toBeEmpty();

    // Short queries (<3 chars) skip matching but must still reset state.
    $component->set('search', 'ب');

    expect($component->instance()->lazyChildren)->toBeEmpty()
        ->and($component->instance()->expanded)->toBeEmpty();
});

test('selectUnit dispatches unit-selected instead of filling a panel', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    $component->call('selectUnit', $this->mid->id)
        ->assertDispatched('unit-selected', id: $this->mid->id);
});

test('toggle collapses an open unit and expands a closed one', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    // Root and mid are expanded by default (first 3 levels).
    expect($component->instance()->expanded)->toContain((string) $this->mid->id);

    // Toggling the already-open mid collapses it.
    $component->call('toggle', (string) $this->mid->id);
    expect($component->instance()->expanded)->not->toContain((string) $this->mid->id);

    // Toggling again re-expands it.
    $component->call('toggle', (string) $this->mid->id);
    expect($component->instance()->expanded)->toContain((string) $this->mid->id)
        ->and($component->instance()->lazyChildren)->toHaveKey($this->mid->id);
});

test('expandAll and collapseAll drive the expanded state', function () {
    $component = Livewire::actingAs($this->user)->test('unit.tree');

    $component->call('expandAll');
    expect($component->instance()->expanded)->not->toBeEmpty();

    $component->call('collapseAll');
    expect($component->instance()->expanded)->toBeEmpty()
        ->and($component->instance()->lazyChildren)->toBeEmpty();
});

// ==================== UnitTreeService (issue #704) ====================

test('UnitTreeService roots returns scope-rooted units only', function () {
    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    $roots = $service->roots($accessible);

    expect($roots->pluck('id')->all())->toBe([$this->root->id]);
});

test('UnitTreeService roots treats a unit with an inaccessible parent as a root', function () {
    $hiddenParent = Unit::create(['name' => 'والد پنهان']);
    $visibleChild = Unit::create(['name' => 'فرزند قابل مشاهده', 'parent_id' => $hiddenParent->id]);

    $service = app(UnitTreeService::class);

    $roots = $service->roots([$visibleChild->id]);

    expect($roots->pluck('id')->all())->toBe([$visibleChild->id]);
});

test('UnitTreeService childrenOf is scope-guarded', function () {
    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    expect($service->childrenOf($this->mid->id, $accessible)->pluck('id')->all())
        ->toBe([$this->leaf->id])
        ->and($service->childrenOf($this->mid->id, [$this->root->id])->pluck('id')->all())
        ->toBe([]);
});

test('UnitTreeService allScoped returns every accessible unit with personnel counts', function () {
    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    $counts = $service->allScoped($accessible)
        ->mapWithKeys(fn (Unit $u) => [$u->id => (int) $u->personnel_count])
        ->all();

    // The mid unit has nobody; leaf has exactly the one person from beforeEach.
    // (Root's count is not asserted — User::factory() links a Person record too.)
    expect($counts)->toHaveCount(3)
        ->and($counts[$this->mid->id])->toBe(0);

    $leafCount = (int) Person::where('u_id', $this->leaf->id)->count();
    expect($counts[$this->leaf->id])->toBe($leafCount)
        ->and($leafCount)->toBe(1);
});

test('UnitTreeService search returns matches and the full ancestor chain', function () {
    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    $result = $service->search('برگ', $accessible);

    expect($result['matches']->pluck('id')->all())->toBe([$this->leaf->id])
        ->and($result['ancestorsToExpand'])->toContain($this->leaf->id, $this->mid->id, $this->root->id);
});

test('UnitTreeService search ignores terms of two characters or fewer', function () {
    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    expect($service->search('بر', $accessible)['matches'])->toHaveCount(0)
        ->and($service->search('', $accessible)['matches'])->toHaveCount(0);
});

test('UnitTreeService search stays inside the accessible scope', function () {
    $hidden = Unit::create(['name' => 'واحد مخفی برگ']);
    $service = app(UnitTreeService::class);

    $result = $service->search('مخفی برگ', [$this->root->id]);

    expect($result['matches']->pluck('id')->all())->not->toContain($hidden->id);
});

test('UnitTreeService ancestor expansion terminates on a parent cycle', function () {
    // root -> mid -> leaf, then close the loop so the chain cycles.
    $this->leaf->update(['parent_id' => $this->root->id]);
    $this->root->update(['parent_id' => $this->leaf->id]);

    $service = app(UnitTreeService::class);
    $accessible = [$this->root->id, $this->mid->id, $this->leaf->id];

    $result = $service->search('میانی', $accessible);

    expect($result['matches']->pluck('id')->all())->toBe([$this->mid->id])
        ->and($result['ancestorsToExpand'])->toContain($this->root->id, $this->leaf->id, $this->mid->id);
});
