<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers(\App\Models\User::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

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

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
});

test('guest is redirected from settings page', function () {
    $this->get('/settings')->assertRedirect('/login');
});

test('authenticated user can load settings page', function () {
    $this->actingAs($this->user);
    $this->get('/settings')->assertStatus(200);
});

test('settings page loads user defaults', function () {
    $this->actingAs($this->user);

    Livewire::test('settings.index')
        ->assertSet('emailNotifications', true)
        ->assertSet('browserNotifications', false)
        ->assertSet('dashboardRefresh', 0)
        ->assertSet('compactMode', false);
});

test('settings page persists saved settings', function () {
    $this->actingAs($this->user);

    Livewire::test('settings.index')
        ->set('emailNotifications', false)
        ->set('browserNotifications', true)
        ->set('dashboardRefresh', 30)
        ->set('compactMode', true)
        ->call('save')
        ->assertSuccessful();

    $this->user->refresh();
    expect($this->user->settings['email_notifications'])->toBeFalse();
    expect($this->user->settings['browser_notifications'])->toBeTrue();
    expect($this->user->settings['dashboard_refresh'])->toBe(30);
    expect($this->user->settings['compact_mode'])->toBeTrue();
});
