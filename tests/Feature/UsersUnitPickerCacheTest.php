<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->actor, 'unit' => $this->actorUnit] = $this->createUserWithUnit(['manage_users']);

    $this->actingAs($this->actor);
});

/**
 * Only counts the picker query. The discriminator is the predicate: the users
 * table query eager-loads `person.unit` with `select *` and has no `is_active`
 * filter; the access CTE has `is_active = true` as a literal (not `= ?`).
 *
 * @return Closure(): int
 */
function users_unit_picker_reads(): Closure
{
    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "units"')
            && str_contains($query->sql, '"is_active" = ?')) {
            $reads++;
        }
    });

    // A regular closure, not an arrow function: arrow functions capture by
    // value, so the reader would be frozen at the cold count.
    return static function () use (&$reads): int {
        return $reads;
    };
}

test('a warm users-index render does not re-read the unit picker list', function () {
    $reads = users_unit_picker_reads();

    $component = Livewire::test('users.index')
        ->set('unit_ids', [$this->actorUnit->id]); // first picker read

    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->call('$refresh');

    expect($reads())->toBe($cold);
});

test('a warm unit-modal render does not re-read the unit picker list', function () {
    $reads = users_unit_picker_reads();

    $component = Livewire::test('users.index')->set('unitModal', true);
    $cold = $reads();
    expect($cold)->toBeGreaterThan(0);

    $component->call('$refresh');

    expect($reads())->toBe($cold);
});

test('the picker query selects a narrow column list, never the full Unit row', function () {
    $sql = [];

    DB::listen(function ($query) use (&$sql): void {
        if (str_contains($query->sql, 'from "units"')
            && str_contains($query->sql, '"is_active" = ?')) {
            $sql[] = $query->sql;
        }
    });

    Livewire::test('users.index')->set('unitModal', true);

    expect($sql)->not->toBeEmpty()
        ->and($sql[0])->toStartWith('select "id", "name", "parent_id", "unit_type_id" from "units"');
});

test('the unit type name survives the cache', function () {
    $type = UnitType::create(['name' => 'نوع کش ۱۹۹۲']);
    Unit::factory()->create([
        'name' => 'واحد نوع‌دار ۱۹۹۲',
        'parent_id' => $this->actorUnit->id,
        'unit_type_id' => $type->id,
    ]);

    $component = Livewire::test('users.index')->set('unitModal', true);
    $component->call('$refresh');

    $row = collect($component->instance()->allUnitsTree())
        ->firstWhere('name', 'واحد نوع‌دار ۱۹۹۲');

    expect($row)->not->toBeNull();
    expect($row['unit_type_name'])->toBe('نوع کش ۱۹۹۲');
});

test('a unit write after the picker cache is warm appears on the next render', function () {
    $component = Livewire::test('users.index')->set('unitModal', true);

    Unit::create(['name' => 'واحد تازه ۱۹۹۲', 'parent_id' => $this->actorUnit->id]);

    $component->call('$refresh');

    expect(array_column($component->instance()->allUnitsTree(), 'name'))
        ->toContain('واحد تازه ۱۹۹۲');
});

test('a warmed wide scope never leaks into a narrower scope', function () {
    ['user' => $wide, 'unit' => $wideUnit] = $this->createUserWithUnit(['manage_users']);
    ['user' => $narrow, 'unit' => $narrowUnit] = $this->createUserWithUnit(['manage_users']);

    $wideUnit->update(['name' => 'واحد گسترده ۱۹۹۲']);
    $narrowUnit->update(['name' => 'واحد محدود ۱۹۹۲']);

    $this->actingAs($wide);
    Session::put('current_unit_id', $wideUnit->id);
    $wideComponent = Livewire::test('users.index')->set('unitModal', true);
    expect(array_column($wideComponent->instance()->allUnitsTree(), 'name'))
        ->toContain('واحد گسترده ۱۹۹۲');

    $this->actingAs($narrow);
    Session::put('current_unit_id', $narrowUnit->id);
    $narrowComponent = Livewire::test('users.index')->set('unitModal', true);

    expect(array_column($narrowComponent->instance()->allUnitsTree(), 'name'))
        ->toContain('واحد محدود ۱۹۹۲')
        ->not->toContain('واحد گسترده ۱۹۹۲');
});

test('an empty scope does not inherit a warmed wider scope', function () {
    Livewire::test('users.index')->set('unitModal', true);

    $this->mock(AccessService::class, function ($mock) {
        $mock->shouldReceive('accessibleUnitIds')->andReturn([]);
    });

    $component = Livewire::test('users.index')->set('unitModal', true);

    expect($component->instance()->allUnitsTree())->toBe([]);
    expect($component->instance()->allUnits())->toBe([]);
});

test('an inactive unit never appears in the picker', function () {
    $this->actorUnit->update(['name' => 'واحد خودی ۱۹۹۲', 'is_active' => false]);

    $component = Livewire::test('users.index')->set('unitModal', true);

    expect(array_column($component->instance()->allUnitsTree(), 'name'))
        ->not->toContain('واحد خودی ۱۹۹۲');
});
