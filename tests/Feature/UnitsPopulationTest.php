<?php

use App\Models\Unit;
use App\Models\UnitPopulation;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use App\Support\BaseUnitTypes;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1246, step 4 — the population input on `units.index`.
 *
 * The rules under test:
 *  - only base units ({8,9,10,18}, `App\Support\BaseUnitTypes`) may carry a
 *    population; a non-base unit silently ignores the payload;
 *  - the row is keyed `(unit_id, now()->year)` — the year is Gregorian
 *    (decision ج), never the Persian year shown in the label;
 *  - clearing the value deletes only the current year, older years stay;
 *  - a write bumps the `disease_maps` namespace, because the map aggregates
 *    `unit_populations` and `UnitPopulation` itself has no model events.
 */
uses(TestCase::class, RefreshDatabase::class);
uses(InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    DB::table('unit_types')->insert([
        ['id' => 5, 'name' => 'مرکز خدمات جامع سلامت شهری', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 8, 'name' => 'پایگاه سلامت ضمیمه', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 9, 'name' => 'خانه بهداشت', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 10, 'name' => 'پایگاه سلامت غیرضمیمه', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 18, 'name' => 'خانه بهداشت کارگری', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Every base type may hang under the actor's own type-5 unit.
    foreach ([5, 8, 9, 10, 18] as $childTypeId) {
        DB::table('unit_type_relationships')->insert([
            'child_unit_type_id' => $childTypeId,
            'allowed_parent_unit_type_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach (['unit_types', 'unit_type_relationships'] as $table) {
        DB::statement("SELECT setval('{$table}_id_seq', (SELECT MAX(id) FROM {$table}), true)");
    }

    // `units/index` treats unit id 1 as ministry HQ; keep created units above it.
    DB::statement("SELECT setval('units_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM units), 1), 1))");
});

/**
 * A user whose own unit is a type-5 container, so base children are valid.
 *
 * @return array{user: User, unit: Unit}
 */
function populationActor(array $permissions = ['organization']): array
{
    ['user' => $user, 'unit' => $unit] = test()->createUserWithUnit($permissions);

    $unit->update(['unit_type_id' => 5]);

    return ['user' => $user, 'unit' => $unit];
}

function baseUnitUnder(Unit $parent, int $typeId = 9): Unit
{
    return Unit::factory()->create([
        'name' => 'واحد پایه '.$typeId.' '.fake()->unique()->numberBetween(1, 99999),
        'parent_id' => $parent->id,
        'unit_type_id' => $typeId,
    ]);
}

test('saving a base unit writes the current-year population', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);
    $year = now()->year;

    Livewire::test('units.index')
        ->call('editUnit', $base->id)
        ->assertSet('population', null)
        ->assertSee('جمعیت تحت پوشش')
        ->set('population', 12345)
        ->call('saveUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('unit_populations', [
        'unit_id' => $base->id,
        'year' => $year,
        'population' => 12345,
    ]);
});

test('a non-base unit ignores a population payload', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $child = Unit::factory()->create([
        'name' => 'مرکز غیرپایه',
        'parent_id' => $parent->id,
        'unit_type_id' => 5,
    ]);

    Livewire::test('units.index')
        ->call('editUnit', $child->id)
        ->assertDontSee('جمعیت تحت پوشش')
        // The field is hidden in Blade, but the check must live on the server.
        ->set('population', 999)
        ->call('saveUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('unit_populations', ['unit_id' => $child->id]);
});

test('editing a base unit pre-fills the current year, not an older one', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);

    UnitPopulation::create(['unit_id' => $base->id, 'year' => now()->year - 1, 'population' => 111]);
    UnitPopulation::create(['unit_id' => $base->id, 'year' => now()->year, 'population' => 222]);

    Livewire::test('units.index')
        ->call('editUnit', $base->id)
        ->assertSet('population', 222);
});

test('clearing the population deletes only the current-year row', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);

    UnitPopulation::create(['unit_id' => $base->id, 'year' => now()->year - 1, 'population' => 111]);
    UnitPopulation::create(['unit_id' => $base->id, 'year' => now()->year, 'population' => 222]);

    Livewire::test('units.index')
        ->call('editUnit', $base->id)
        ->set('population', '')
        ->call('saveUnit')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('unit_populations', [
        'unit_id' => $base->id,
        'year' => now()->year,
    ]);
    $this->assertDatabaseHas('unit_populations', [
        'unit_id' => $base->id,
        'year' => now()->year - 1,
        'population' => 111,
    ]);
});

test('saving a population bumps the disease_maps namespace', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);
    $cache = app(CacheInvalidationServiceInterface::class);

    $before = $cache->getVersion('disease_maps');

    Livewire::test('units.index')
        ->call('editUnit', $base->id)
        ->set('population', 500)
        ->call('saveUnit')
        ->assertHasNoErrors();

    expect($cache->getVersion('disease_maps'))->toBeGreaterThan($before);
});

test('an unchanged population does not bump the disease_maps namespace', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);
    UnitPopulation::create(['unit_id' => $base->id, 'year' => now()->year, 'population' => 500]);

    $cache = app(CacheInvalidationServiceInterface::class);
    $before = $cache->getVersion('disease_maps');

    Livewire::test('units.index')
        ->call('editUnit', $base->id)
        ->set('population', 500)
        ->call('saveUnit')
        ->assertHasNoErrors();

    expect($cache->getVersion('disease_maps'))->toBe($before);
});

test('saveUnit still requires organization when a population is submitted', function () {
    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    $base = baseUnitUnder($parent);

    $component = Livewire::test('units.index')->assertStatus(200);

    // Load the form while the permission is still held — same open-tab shape
    // as `RevokedPermissionMutatorTest`.
    $component->call('editUnit', $base->id)->assertStatus(200);

    $user->revokePermissionTo('organization');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component
        ->set('population', 777)
        ->call('saveUnit')
        ->assertForbidden();

    $this->assertDatabaseMissing('unit_populations', ['unit_id' => $base->id]);
});

test('every approved base type is writable and no second list is used', function () {
    expect(BaseUnitTypes::IDS)->toBe([8, 9, 10, 18]);

    ['user' => $user, 'unit' => $parent] = populationActor();
    $this->actingAs($user);

    foreach (BaseUnitTypes::IDS as $typeId) {
        $base = baseUnitUnder($parent, $typeId);

        Livewire::test('units.index')
            ->call('editUnit', $base->id)
            ->set('population', 1000 + $typeId)
            ->call('saveUnit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('unit_populations', [
            'unit_id' => $base->id,
            'year' => now()->year,
            'population' => 1000 + $typeId,
        ]);
    }
});
