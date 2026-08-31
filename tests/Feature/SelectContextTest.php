<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/** @covers \App\Models\Unit */

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

test('guest is redirected from select-context', function () {
    $this->get('/select-context')->assertRedirect('/login');
});

test('select-context redirects to dashboard for single unit', function () {
    $this->actingAs($this->user);
    $this->get('/select-context')->assertRedirect('/dashboard');
});

test('select-context loads when multiple units exist', function () {
    $secondUnit = Unit::create(['name' => 'واحد دوم']);
    $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

    $this->actingAs($this->user);
    Livewire::test('select-context')->assertStatus(200);
});
