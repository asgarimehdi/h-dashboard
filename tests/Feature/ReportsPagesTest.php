<?php

namespace Tests\Feature;

use App\Models\Boundary;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->seedLookupTables();

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
    // /reports/* is gated by `manage_personnel` (routes/web.php) — the HTTP
    // assertion below needs that permission in addition to manage_hardware.
    $this->user->givePermissionTo('manage_personnel');

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

test('advanced reports page ships the Highcharts core and accessibility modules', function () {
    $response = $this->actingAs($this->user)->get('/reports/tickets');

    $response->assertSee('js/chart/highcharts.js');
    $response->assertSee('js/chart/accessibility.js');
});

test('advanced reports page does not ship the unused treemap, treegraph and exporting modules', function () {
    $response = $this->actingAs($this->user)->get('/reports/tickets');

    $response->assertDontSee('js/chart/treemap.js');
    $response->assertDontSee('js/chart/treegraph.js');
    $response->assertDontSee('js/chart/exporting.js');
});
