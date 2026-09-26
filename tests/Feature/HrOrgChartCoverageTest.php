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

// Coverage: the hr.org-chart page's selection guard rails. The lazy-loading,
// search-reset and ancestor-expansion tests that used to live here moved to
// UnitTreeLivewireTest when the tree became the reusable `unit.tree` (#704).

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

test('selectUnit does not select units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد ممنوع']);

    // A user scoped to a different branch only.
    $otherRoot = Unit::create(['name' => 'شاخه دیگر']);
    $otherUser = User::factory()->create();
    $otherUser->units()->attach($otherRoot->id, ['role' => 'responsible', 'is_primary' => true]);
    $otherUser->givePermissionTo('view_hr_dashboard');

    $component = Livewire::actingAs($otherUser)->test('hr.org-chart')
        ->call('selectUnit', $outsider->id);

    expect($component->get('selectedUnit'))->toBeNull()
        ->and($component->get('selectedPersonnelTotal'))->toBe(0);
});

test('a unit-selected event from outside the scope is ignored', function () {
    $outsider = Unit::create(['name' => 'واحد ممنوع']);

    $component = Livewire::actingAs($this->user)->test('hr.org-chart')
        ->dispatch('unit-selected', id: $outsider->id);

    expect($component->get('selectedUnit'))->toBeNull();
});

test('a unit-selected event from the tree fills the detail panel', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart')
        ->dispatch('unit-selected', id: $this->root->id);

    // The factory user's backing Person lands on the first unit (root), so
    // the panel must show exactly that unit's personnel.
    expect($component->get('selectedUnit')->id)->toBe((int) $this->root->id)
        ->and($component->get('selectedPersonnel'))->toHaveCount(1)
        ->and($component->get('selectedPersonnelTotal'))->toBe(1);
});

test('the page hands its personnel counts to the tree as badge data', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart');

    // Units with personnel carry their count; personnel-less units are absent
    // and the badge falls back to 0 → «خالی».
    expect($component->get('personCounts'))
        ->toHaveKey($this->root->id, 1)
        ->toHaveKey($this->leaf->id, 1)
        ->not->toHaveKey($this->mid->id);
});
