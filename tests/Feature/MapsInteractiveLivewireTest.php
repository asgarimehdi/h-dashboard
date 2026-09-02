<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\Unit::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->unit = Unit::create([
        'name' => 'مرکز تست',
        'lat' => 35.6892,
        'lng' => 51.3890,
    ]);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('map');

    Session::put('current_unit_id', $this->unit->id);
});

test('interactive map mounts successfully', function () {
    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertOk();
});

test('interactive map shows unit count', function () {
    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertSee('نقشه واحدهای سازمانی')
        ->assertSet('units', fn ($units) => count($units) >= 1);
});

test('interactive map loads units with coordinates', function () {
    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertSet('units', function ($units) {
            return collect($units)->contains('id', $this->unit->id);
        });
});

test('interactive map excludes units without coordinates', function () {
    Unit::create(['name' => 'واحد بدون مختصات']); // no lat/lng

    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertSet('units', function ($units) {
            return ! collect($units)->contains('name', 'واحد بدون مختصات');
        });
});

test('interactive map loads child unit with coordinates', function () {
    $child = Unit::create([
        'name' => 'واحد فرعی',
        'lat' => 35.6800,
        'lng' => 51.3800,
        'parent_id' => $this->unit->id,
    ]);

    $childId = $child->id;

    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertSet('units', function ($units) use ($childId) {
            $ids = collect($units)->pluck('id')->toArray();
            return in_array($childId, $ids);
        });
});

test('interactive map loads unit type for markers', function () {
    $type = UnitType::create(['name' => 'مرکز']);
    $this->unit->update(['unit_type_id' => $type->id]);

    Livewire::actingAs($this->user)
        ->test('maps.interactive')
        ->assertSet('units', function ($units) {
            $unit = collect($units)->firstWhere('id', $this->unit->id);
            return $unit && isset($unit['unit_type']) && $unit['unit_type']['name'] === 'مرکز';
        });
});

test('interactive map is protected by map permission', function () {
    $noPermUser = User::factory()->create();
    Session::put('current_unit_id', $this->unit->id);

    $this->actingAs($noPermUser)
        ->get('/maps/interactive')
        ->assertStatus(403);
});
