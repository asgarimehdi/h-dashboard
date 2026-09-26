<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HrAnalyticsController;
use App\Http\Controllers\Api\HrStatsController;
use App\Http\Controllers\Api\OrgChartController;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

covers(OrgChartController::class, HrStatsController::class, HrAnalyticsController::class);

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): the generic tree mechanics these tests used to pin on
// `hr.org-chart` now live in UnitTreeLivewireTest against `unit.tree` (issue
// #704). What is left here is the HR PAGE's own behaviour: that it composes
// the tree, passes personnel counts into it, and that the detail panel still
// fills on selection and refuses an out-of-scope unit.

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

test('page composes the shared tree and renders unit names', function () {
    Livewire::actingAs($this->user)->test('hr.org-chart')
        ->assertStatus(200)
        ->assertSee('ریشه');
});

test('the tree the page embeds lazy-loads children for an expanded unit', function () {
    $tree = Livewire::actingAs($this->user)->test('unit.tree')
        ->call('loadChildren', $this->mid->id);

    expect($tree->instance()->lazyChildren)->toHaveKey($this->mid->id)
        ->and($tree->instance()->lazyChildren[$this->mid->id]->pluck('id'))
        ->toContain($this->leaf->id);
});

test('the tree ignores units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد بیرونی']);

    $tree = Livewire::actingAs($this->user)->test('unit.tree')
        ->call('loadChildren', $outsider->id);

    expect($tree->instance()->lazyChildren)->not->toHaveKey($outsider->id);
});

test('the tree expands the full ancestor chain of deep matches', function () {
    $tree = Livewire::actingAs($this->user)->test('unit.tree')
        ->set('search', 'برگ');

    $expanded = array_map('intval', $tree->instance()->expanded);

    expect($expanded)->toContain($this->leaf->id)
        ->and($expanded)->toContain($this->mid->id)
        ->and($expanded)->toContain($this->root->id);
});

test('the tree clears previous expansion state on a short search', function () {
    $tree = Livewire::actingAs($this->user)->test('unit.tree');

    expect($tree->instance()->lazyChildren)->not->toBeEmpty();

    // Short queries (<3 chars) skip matching but must still reset state.
    $tree->set('search', 'ب');

    expect($tree->instance()->lazyChildren)->toBeEmpty()
        ->and($tree->instance()->expanded)->toBeEmpty();
});

test('page passes its personnel counts into the tree badge', function () {
    $counts = Livewire::actingAs($this->user)->test('hr.org-chart')
        ->get('personCounts');

    expect($counts)->toHaveKey($this->leaf->id)
        ->and($counts[$this->leaf->id])->toBe(1);
});

test('selectUnit does not select units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد ممنوع']);

    // A user scoped to a different branch only.
    $otherRoot = Unit::create(['name' => 'شاخه دیگر']);
    $otherUser = User::factory()->create();
    $otherUser->units()->attach($otherRoot->id, ['role' => 'responsible', 'is_primary' => true]);
    $otherUser->givePermissionTo('view_hr_dashboard');

    $component = Livewire::actingAs($otherUser)->test('hr.org-chart')
        ->call('selectUnit', $outsider->id);

    expect($component->instance()->selectedUnit)->toBeNull()
        ->and($component->instance()->selectedPersonnelTotal)->toBe(0);
});

test('selectUnit fills the detail panel for an in-scope unit', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart')
        ->call('selectUnit', $this->leaf->id);

    expect($component->instance()->selectedUnit)->not->toBeNull()
        ->and($component->instance()->selectedUnit->id)->toBe($this->leaf->id)
        ->and($component->instance()->selectedPersonnelTotal)->toBe(1);
});

test('the page responds to the unit-selected event from the tree', function () {
    // The tree dispatches `unit-selected`; the page listens via #[On].
    // Driving the event is the contract a second consumer relies on (#704).
    Livewire::actingAs($this->user)->test('hr.org-chart')
        ->dispatch('unit-selected', unitId: $this->leaf->id)
        ->assertSet('selectedPersonnelTotal', 1);
});
