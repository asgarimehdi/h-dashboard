<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    $this->user = User::factory()->create([
        'n_code' => '1234567890',
        'password' => Hash::make('password'),
    ]);
});

test('guest is redirected from dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated user can load dashboard', function () {
    $this->actingAs($this->user);
    $this->get('/dashboard')->assertStatus(200);
});

test('user without accessible unit gets zero stats, not 500', function () {
    $orphan = User::factory()->create([
        'n_code' => '0000000000',
        'password' => Hash::make('password'),
    ]);
    Person::where('n_code', '0000000000')->update(['u_id' => null]);

    $this->actingAs($orphan);
    $this->get('/dashboard')->assertStatus(200);
});

test('dashboard shows empty-scope message when no unit is accessible', function () {
    $orphan = User::factory()->create([
        'n_code' => '0000000000',
        'password' => Hash::make('password'),
    ]);
    Person::where('n_code', '0000000000')->update(['u_id' => null]);

    $this->actingAs($orphan);

    Livewire::test('dashboard')
        ->assertStatus(200)
        ->assertSee('واحدی برای نمایش انتخاب نشده')
        ->assertSet('totalPersons', 0)
        ->assertSet('totalTickets', 0);
});

test('dashboard renders expected sections', function () {
    $this->actingAs($this->user);

    Livewire::test('dashboard')
        ->assertSee('داشبورد مدیریت اطلاعات سلامت')
        ->assertSee('روند ایجاد تیکت‌ها')
        ->assertSee('وضعیت وظایف');
});

test('dashboard ships the Highcharts core and accessibility modules', function () {
    $response = $this->actingAs($this->user)->get('/dashboard');

    // accessibility.js is load-bearing: Highcharts v12 ships a11y separately
    // and its core warns at runtime when the module is absent (#957).
    $response->assertSee('js/chart/highcharts.js');
    $response->assertSee('js/chart/accessibility.js');
});

test('dashboard does not ship the unused treemap, treegraph and exporting modules', function () {
    $response = $this->actingAs($this->user)->get('/dashboard');

    $response->assertDontSee('js/chart/treemap.js');
    $response->assertDontSee('js/chart/treegraph.js');
    $response->assertDontSee('js/chart/exporting.js');
});

test('dashboard with an active interval does not call $wire.mount()', function () {
    $this->user->settings = ['dashboard_refresh' => 60];
    $this->user->save();
    $this->actingAs($this->user);

    // #1164: `mount` is a protected Livewire lifecycle method; the refresh
    // timer must target refreshStats, never mount.
    Livewire::test('dashboard')
        ->assertStatus(200)
        ->assertDontSee('$wire.mount()')
        ->assertSee('refreshStats');
});

test('refreshStats re-runs the aggregation after invalidation', function () {
    $this->actingAs($this->user);

    $component = Livewire::test('dashboard')->assertSet('totalPersons', 1);

    // A new record in scope plus a version bump: the operation count is now
    // stale in the cache, and refreshStats() must re-run the aggregation
    // (a bare $refresh would re-render without re-querying).
    Person::create([
        'n_code' => '1122334455',
        'f_name' => 'دوم',
        'l_name' => 'کاربر',
        'u_id' => $this->unit->id,
        's_id' => 1,
        't_id' => 1,
        'e_id' => 1,
        'r_id' => 1,
    ]);
    app(CacheInvalidationServiceInterface::class)->increment('dashboard');

    $component->call('refreshStats')->assertSet('totalPersons', 2);
});

test('dashboard_refresh = 0 renders no timer', function () {
    $this->user->settings = ['dashboard_refresh' => 0];
    $this->user->save();
    $this->actingAs($this->user);

    Livewire::test('dashboard')
        ->assertStatus(200)
        ->assertSet('refreshInterval', 0)
        ->assertDontSee('setInterval');
});
