<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use App\Models\Hardware;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Boundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->person = Person::create([
        'n_code' => '1234567890',
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    
    $this->user = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->user->givePermissionTo('manage_hardware');
    
    Session::put('current_unit_id', $this->unit->id);
});

test('report pages render successfully for authenticated user', function () {
    $pages = [
        'reports.advanced' => '/reports/tickets',
        'reports.units' => '/reports/units',
        'reports.todos' => '/reports/todos',
        'reports.persons' => '/reports/persons',
        'reports.map-no-boundary' => '/reports/map-no-boundary',
    ];

    foreach ($pages as $component => $url) {
        Livewire::actingAs($this->user)
            ->test($component)
            ->assertOk();
        
        $this->actingAs($this->user)
            ->get($url)
            ->assertStatus(200);
    }
});

test('reports reflect data presence and organizational scope', function () {
    // Seed a person in this unit
    $this->person;

    // Seed a person in another unit
    $otherUnit = Unit::create(['name' => 'واحد دیگر']);
    Person::create([
        'n_code' => '0987654321',
        'f_name' => 'خارجی',
        'l_name' => 'کاربر',
        'u_id' => $otherUnit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.persons')
        ->assertSee('تست کاربر')
        ->assertDontSee('خارجی کاربر');
});

test('map-no-boundary page renders without crash when boundary is missing', function () {
    // Unit exists but has no Boundary record
    Livewire::actingAs($this->user)
        ->test('reports.map-no-boundary')
        ->assertOk();
});

test('reports pages render Persian labels', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertSee('گزارش تیکت‌ها');
});
