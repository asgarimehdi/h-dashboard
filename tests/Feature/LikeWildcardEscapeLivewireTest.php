<?php

use App\Models\ActivityLog;
use App\Models\Estekhdam;
use App\Models\MaintenanceSchedule;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->user, 'unit' => $this->unit] = $this->createUserWithUnit([
        'kargozini', 'manage_users', 'manage_roles', 'manage_hardware',
        'view_all_tickets', 'create_ticket', 'view_assigned_tickets',
    ]);

    $this->actingAs($this->user);
});

test('percent is literal in kargozini lookup lists', function (string $component, string $method, string $model) {
    $model::create(['name' => 'Qwx%Literal']);
    $model::create(['name' => 'QwxPlain']);

    $names = Livewire::test($component)
        ->set('search', 'Qwx%')
        ->instance()
        ->{$method}()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Qwx%Literal']);
})->with([
    ['kargozini.estekhdam', 'estekhdams', Estekhdam::class],
    ['kargozini.radif', 'radifs', Radif::class],
    ['kargozini.semat', 'semats', Semat::class],
    ['kargozini.tahsil', 'tahsils', Tahsil::class],
]);

test('percent is literal in activity log description and type', function () {
    $rows = [
        ['type' => 'created', 'description' => 'Qwx%Literal'],   // description should match
        ['type' => 'created', 'description' => 'QwxPlain'],      // must not match
        ['type' => 'Qwx%Type', 'description' => 'QwxTypeOnly'], // type should match
        ['type' => 'QwxType', 'description' => 'QwxTypeOnlyPlain'], // must not match
    ];

    foreach ($rows as $row) {
        ActivityLog::create($row + ['user_id' => $this->user->id]);
    }

    $descriptions = Livewire::test('activity-log.index')
        ->set('search', 'Qwx%')
        ->instance()
        ->logs()
        ->pluck('description')
        ->all();

    expect($descriptions)->toHaveCount(2)
        ->toContain('Qwx%Literal')
        ->toContain('QwxTypeOnly');

    expect($descriptions)->not->toContain('QwxPlain')
        ->not->toContain('QwxTypeOnlyPlain');
});

test('percent is literal in maintenance titles', function () {
    foreach (['Qwx%Literal', 'QwxPlain'] as $title) {
        MaintenanceSchedule::create([
            'title' => $title,
            'unit_id' => $this->unit->id,
            'frequency' => 'weekly',
            'recurrence_interval' => 1,
            'next_due_at' => now()->addWeek(),
        ]);
    }

    $titles = Livewire::test('maintenance.index')
        ->set('search', 'Qwx%')
        ->instance()
        ->schedules()
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Qwx%Literal']);
});

test('percent is literal in permissions name and label', function () {
    Permission::create(['name' => 'Qwx%Literal', 'label' => 'L1']);
    Permission::create(['name' => 'QwxPlain', 'label' => 'L2']);

    $names = Livewire::test('permissions.index')
        ->set('search', 'Qwx%')
        ->instance()
        ->permissions()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Qwx%Literal']);
});

test('percent is literal in roles name and label', function () {
    Role::create(['name' => 'Qwx%Literal', 'label' => 'R1']);
    Role::create(['name' => 'QwxPlain', 'label' => 'R2']);

    $names = Livewire::test('roles.index')
        ->set('search', 'Qwx%')
        ->instance()
        ->roles()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Qwx%Literal']);
});

test('percent is literal in units index', function () {
    Unit::create(['name' => 'Qwx%Literal', 'parent_id' => $this->unit->id]);
    Unit::create(['name' => 'QwxPlain', 'parent_id' => $this->unit->id]);

    $names = Livewire::test('units.index')
        ->set('search', 'Qwx%')
        ->instance()
        ->units()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Qwx%Literal']);
});

test('percent is literal in every ticket unit picker', function () {
    foreach (['Qwx%Literal', 'QwxPlain'] as $name) {
        Unit::create([
            'name' => $name,
            'parent_id' => $this->unit->id,
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);
    }

    $monitoring = Livewire::test('tickets.monitoring')
        ->set('unitSearch', 'Qwx%')
        ->get('filterUnits');

    $create = Livewire::test('tickets.create')
        ->set('search', 'Qwx%')
        ->call('loadData')
        ->get('units');

    $inbox = Livewire::test('tickets.inbox')
        ->set('unitSearch', 'Qwx%')
        ->get('units');

    expect(array_column($monitoring, 'name'))->toBe(['Qwx%Literal'])
        ->and(array_column($create, 'name'))->toBe(['Qwx%Literal'])
        ->and(array_column($inbox, 'name'))->toBe(['Qwx%Literal']);

    $bulk = Livewire::test('tickets.inbox')
        ->set('bulkUnitSearch', 'Qwx%')
        ->instance()
        ->bulkUnits();

    expect(array_column($bulk, 'name'))->toBe(['Qwx%Literal']);
});

test('underscore matches only a literal underscore', function () {
    Radif::create(['name' => 'Qwx_One']);
    Radif::create(['name' => 'QwxXOne']);

    $names = Livewire::test('kargozini.radif')
        ->set('search', 'Qwx_O')
        ->instance()
        ->radifs()
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Qwx_One']);
});

test('escaping does not fold persian variants', function () {
    $zwnj = 'حرفه'."\u{200C}".'ای';

    Radif::create(['name' => 'آموزش']);
    Radif::create(['name' => $zwnj]);

    foreach (['آموزش', $zwnj] as $term) {
        $names = Livewire::test('kargozini.radif')
            ->set('search', $term)
            ->instance()
            ->radifs()
            ->pluck('name')
            ->all();

        expect($names)->toContain($term);
    }
});
