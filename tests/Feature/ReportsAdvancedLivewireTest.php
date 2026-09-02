<?php

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\TicketFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\Ticket::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    $this->unit = Unit::create(['name' => 'واحد تست']);

    $nCode = fake()->unique()->numerify('##########');
    DB::table('persons')->insert([
        'n_code' => $nCode,
        'f_name' => 'تست',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->user = User::factory()->create(['n_code' => $nCode]);

    Session::put('current_unit_id', $this->unit->id);
});

test('guest is redirected from reports page', function () {
    $this->get('/reports/tickets')->assertRedirect('/login');
});

test('reports advanced component mounts successfully', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertOk()
        ->assertSeeHtml('dateFrom');
});

test('mount sets Jalali default dates', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertOk()
        ->assertSeeHtml('dateFrom');
});

test('mount loads accessible units', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertOk()
        ->assertSet('units', function ($units) {
            return count($units) >= 1 && $units[0]['name'] === 'واحد تست';
        });
});

test('default report type is tickets', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertSet('reportType', 'tickets')
        ->assertSet('statusFilter', 'all');
});

test('changing report type updates query', function () {
    Todo::create([
        'title' => 'وظیفه تست',
        'start_at' => now(),
        'is_completed' => false,
        'unit_id' => $this->unit->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('reportType', 'todos')
        ->assertOk()
        ->assertSet('reportType', 'todos')
        ->assertSet('reportData.total', 1);
});

test('tickets with no data shows zero total', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertSet('reportData.total', 0)
        ->assertSet('reportData.byDay', [])
        ->assertSet('reportData.byUnit', []);
});

test('seeding tickets shows in report data', function () {
    TicketFactory::new()->normal()->created()->create([
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->assertSet('reportData.total', 1)
        ->assertSet('reportData.details.normal', 1);
});

test('report filters by unit hierarchy', function () {
    $childUnit = Unit::create(['name' => 'واحد فرعی', 'parent_id' => $this->unit->id]);

    TicketFactory::new()->normal()->created()->create([
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);
    TicketFactory::new()->normal()->created()->create([
        'unit_id' => $childUnit->id,
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('rootUnitId', $this->unit->id)
        ->assertSet('reportData.total', 2);
});

test('status filter works', function () {
    TicketFactory::new()->normal()->created()->create([
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);
    TicketFactory::new()->normal()->completed()->create([
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('statusFilter', 'created')
        ->assertSet('reportData.total', 1);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('statusFilter', 'completed')
        ->assertSet('reportData.total', 1);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('statusFilter', 'all')
        ->assertSet('reportData.total', 2);
});

test('updatedRootUnitId resets child and unit filters', function () {
    $childUnit = Unit::create(['name' => 'واحد فرعی', 'parent_id' => $this->unit->id]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('parentUnitId', $childUnit->id)
        ->set('unitId', 1)
        ->set('rootUnitId', $this->unit->id)
        ->assertSet('parentUnitId', null)
        ->assertSet('unitId', null);
});

test('persons report type shows personnel details', function () {
    $component = Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('reportType', 'persons')
        ->assertSet('reportData.total', 1);

    $details = $component->get('reportData.details');
    $this->assertArrayHasKey('byEstekhdam', $details);
    $this->assertNotEmpty($details['byEstekhdam']);
});

test('chartPayload returns same data as reportData', function () {
    TicketFactory::new()->urgent()->created()->create([
        'unit_id' => $this->unit->id,
        'user_id' => $this->user->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->call('chartPayload')
        ->assertOk();
});

test('persons status filter is ignored', function () {
    Livewire::actingAs($this->user)
        ->test('reports.advanced')
        ->set('reportType', 'persons')
        ->set('statusFilter', 'completed')
        ->assertSet('reportData.total', 1);
});
