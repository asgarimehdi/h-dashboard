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
        ->assertSee('روند تیکت‌ها')
        ->assertSee('وضعیت وظایف');
});
