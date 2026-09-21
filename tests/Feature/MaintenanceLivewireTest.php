<?php

use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
    DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

    foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
        DB::unprepared(
            "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT COALESCE(MAX(id),1) FROM {$table}))"
        );
    }

    $this->unit = Unit::create(['name' => 'واحد تست']);

    $this->user = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->user->givePermissionTo('manage_hardware');
});

test('guest is redirected from maintenance page', function () {
    $this->get('/maintenance')->assertRedirect('/login');
});

test('authenticated user without manage_hardware permission is denied', function () {
    $user = User::factory()->create([
        'n_code' => (string) fake()->unique()->numerify('##########'),
        'password' => Hash::make('password'),
    ]);
    $unit = Unit::create(['name' => 'واحد دیگر']);
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

    $this->actingAs($user);
    $this->get('/maintenance')->assertStatus(403);
});

test('authenticated user with permission can load maintenance page', function () {
    $this->actingAs($this->user);
    Livewire::test('maintenance.index')
        ->assertStatus(200);
});

test('create schedule via livewire', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'بازدید ماهانه سرور')
        ->set('frequency', 'monthly')
        ->set('recurrenceInterval', 1)
        ->call('createSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('maintenance_schedules', [
        'title' => 'بازدید ماهانه سرور',
        'frequency' => 'monthly',
        'recurrence_interval' => 1,
    ]);
});

test('edit schedule via livewire', function () {
    $this->actingAs($this->user);

    $schedule = MaintenanceSchedule::create([
        'title' => 'عنوان قدیمی',
        'frequency' => 'weekly',
        'recurrence_interval' => 1,
        'next_due_at' => now()->addWeek(),
    ]);

    Livewire::test('maintenance.index')
        ->call('editSchedule', $schedule->id)
        ->assertSet('title', 'عنوان قدیمی')
        ->assertSet('frequency', 'weekly')
        ->set('title', 'عنوان جدید')
        ->call('updateSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('maintenance_schedules', [
        'id' => $schedule->id,
        'title' => 'عنوان جدید',
    ]);
});

test('delete schedule via livewire', function () {
    $this->actingAs($this->user);

    $schedule = MaintenanceSchedule::create([
        'title' => 'برای حذف',
        'frequency' => 'daily',
        'recurrence_interval' => 1,
    ]);

    Livewire::test('maintenance.index')
        ->call('delete', $schedule->id);

    $this->assertDatabaseMissing('maintenance_schedules', ['id' => $schedule->id]);
});

test('validation fails for empty title', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', '')
        ->call('createSchedule')
        ->assertHasErrors(['title']);
});

test('validation fails for invalid frequency', function () {
    $this->actingAs($this->user);

    Livewire::test('maintenance.index')
        ->set('title', 'تست')
        ->set('frequency', 'invalid')
        ->call('createSchedule')
        ->assertHasErrors(['frequency']);
});
