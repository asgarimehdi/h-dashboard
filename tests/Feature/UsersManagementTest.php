<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Unit;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);

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
    
    $this->admin = User::factory()->create(['n_code' => $this->person->n_code]);
    $this->admin->givePermissionTo('manage_users');
    $this->admin->givePermissionTo('manage_roles');
    
    Session::put('current_unit_id', $this->unit->id);
});

test('users index renders and filters results', function () {
    $otherUser = User::factory()->create(['name' => 'کاربر دیگر', 'n_code' => '1112223334']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->assertOk()
        ->assertSee('تست کاربر')
        ->assertSee('کاربر دیگر');

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'کاربر دیگر')
        ->assertSee('کاربر دیگر')
        ->assertDontSee('تست کاربر');
});

test('create user persists data and assigns roles', function () {
    $newUser = User::factory()->make(['n_code' => '9998887776']);
    
    Livewire::actingAs($this->admin)
        ->test('users.create')
        ->set('name', 'کاربر جدید')
        ->set('n_code', '9998887776')
        ->set('email', 'new@test.com')
        ->set('unit_id', $this->unit->id)
        ->call('store');

    $this->assertDatabaseHas('users', ['n_code' => '9998887776']);
});

test('change password updates password correctly', function () {
    $user = $this->admin;
    $oldPassword = 'old-password';
    $newPassword = 'new-password-123';
    
    // Manually set password for test
    $user->update(['password' => bcrypt($oldPassword)]);

    Livewire::actingAs($this->admin)
        ->test('auth.changepassword')
        ->set('old_password', $oldPassword)
        ->set('new_password', $newPassword)
        ->set('new_password_confirmation', $newPassword)
        ->call('updatePassword');

    $this->assertTrue(\Illuminate\Support\Facades\Hash::check($newPassword, $user->fresh()->password));
});

test('roles and permissions pages render and allow modifications', function () {
    Livewire::actingAs($this->admin)
        ->test('roles.index')
        ->assertOk();

    Livewire::actingAs($this->admin)
        ->test('permissions.index')
        ->assertOk();
});

test('users management pages are protected by RBAC', function () {
    $noPermUser = User::factory()->create();
    
    $urls = ['/users', '/roles', '/permissions'];

    foreach ($urls as $url) {
        $this->actingAs($noPermUser)
            ->get($url)
            ->assertStatus(403);
    }
});
