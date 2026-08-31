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

/** @covers \App\Models\User */

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

test('guest is redirected from profile page', function () {
    $this->get('/profile')->assertRedirect('/login');
});

test('authenticated user can load profile page', function () {
    $this->actingAs($this->user);
    $this->get('/profile')->assertStatus(200);
});

test('profile page shows user name and stats placeholders', function () {
    $this->actingAs($this->user);

    Livewire::test('profile.index')
        ->assertSee('پروفایل من')
        ->assertSee('تست کاربر')
        ->assertSee('تیکت‌ها')
        ->assertSee('وظایف')
        ->assertSee('فعالیت‌ها');
});

test('profile page shows no tickets empty state initially', function () {
    $this->actingAs($this->user);

    Livewire::test('profile.index')
        ->assertSee('هنوز تیکتی ثبت نشده');
});
