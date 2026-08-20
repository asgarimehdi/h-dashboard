<?php

namespace Tests\Feature;

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

test('guest is redirected to login from protected page', function () {
    $this->get('/dashboard')
        ->assertRedirect('/login');
});

test('web login via livewire authenticates user', function () {
    Livewire::test('auth.login')
        ->set('n_code', '1234567890')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user);
});

test('web login fails with invalid credentials', function () {
    Livewire::test('auth.login')
        ->set('n_code', '1234567890')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors(['n_code']);
});

test('logout invalidates session and redirects', function () {
    $this->actingAs($this->user);

    $this->get('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

test('api login returns sanctum token for valid credentials', function () {
    $response = $this->postJson('/api/login', [
        'n_code' => '1234567890',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['token']);

    expect($response->json('token'))->toBeString();
});

test('api login fails for invalid credentials', function () {
    $response = $this->postJson('/api/login', [
        'n_code' => '1234567890',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Credentials not match']);
});

test('api login fails for missing fields', function () {
    $response = $this->postJson('/api/login', [
        'n_code' => '1234567890',
    ]);

    $response->assertStatus(422);
});
