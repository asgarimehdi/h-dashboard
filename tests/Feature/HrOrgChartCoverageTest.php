<?php

namespace Tests\Feature;


use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** @covers \App\Http\Controllers\Api\HrController */

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): HrLivewireTest covers mount/render/expand/collapse and
// selectUnit happy-path, but not the lazy-loading guard rails, the search
// reset path, the ancestor-chain expansion, or the out-of-scope select error.

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
    $component = Livewire::actingAs($this->user)->test('hr.org-chart');

    $component->call('loadChildren', $this->mid->id);

    expect($component->instance()->lazyChildren)->toHaveKey($this->mid->id)
        ->and($component->instance()->lazyChildren[$this->mid->id]->pluck('id'))
        ->toContain($this->leaf->id);
});

test('loadChildren ignores units outside organizational scope', function () {
    $outsider = Unit::create(['name' => 'واحد بیرونی']);

    $component = Livewire::actingAs($this->user)->test('hr.org-chart')
        ->call('loadChildren', $outsider->id);

    expect($component->instance()->lazyChildren)->not->toHaveKey($outsider->id);
});

test('updatedSearch expands full ancestor chain of deep matches', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart');

    $component->set('search', 'برگ');

    $expanded = array_map('intval', $component->instance()->expanded);

    expect($expanded)->toContain($this->leaf->id)
        ->and($expanded)->toContain($this->mid->id)
        ->and($expanded)->toContain($this->root->id);
});

test('updatedSearch clears previous expansion state first', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart');

    expect($component->instance()->lazyChildren)->not->toBeEmpty();

    // Short queries (<3 chars) skip matching but must still reset state.
    $component->set('search', 'ب');

    expect($component->instance()->lazyChildren)->toBeEmpty()
        ->and($component->instance()->expanded)->toBeEmpty();
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

test('toggle collapses an open unit and expands a closed one', function () {
    $component = Livewire::actingAs($this->user)->test('hr.org-chart');

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
