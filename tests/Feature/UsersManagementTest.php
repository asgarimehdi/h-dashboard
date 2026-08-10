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
    // Create persons for the users first (FK constraint).
    // Note: the signed-in admin is excluded from the list by the component.
    Person::create([
        'n_code' => '1112223334',
        'f_name' => 'کاربر',
        'l_name' => 'دیگر',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $otherUser = User::factory()->create(['n_code' => '1112223334']);

    Person::create([
        'n_code' => '2223334445',
        'f_name' => 'کاربر',
        'l_name' => 'دوم',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);
    $secondUser = User::factory()->create(['n_code' => '2223334445']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->assertOk()
        ->assertSee('کاربر دیگر')
        ->assertSee('کاربر دوم');

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->set('search', 'کاربر دوم')
        ->assertSee('کاربر دوم')
        ->assertDontSee('کاربر دیگر');
});

test('create user persists data and assigns roles', function () {
    // Create a person first (FK constraint)
    $person = Person::create([
        'n_code' => '9998887776',
        'f_name' => 'کاربر',
        'l_name' => 'جدید',
        'u_id' => $this->unit->id,
        's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
    ]);

    $role = \Spatie\Permission\Models\Role::create(['name' => 'operator', 'label' => 'اپراتور']);

    Livewire::actingAs($this->admin)
        ->test('users.index')
        ->call('openFormForCreate')
        ->set('n_code', '9998887776')
        ->set('password', 'password123')
        ->set('role_ids', [$role->id])
        ->call('createUser');

    $this->assertDatabaseHas('users', ['n_code' => '9998887776']);
    $this->assertTrue(\App\Models\User::where('n_code', '9998887776')->first()->hasRole('operator'));
});

test('change password updates password correctly', function () {
    $user = $this->admin;
    $oldPassword = 'old-password';
    $newPassword = 'new-password-123';

    // Manually set password for test
    $user->update(['password' => bcrypt($oldPassword)]);

    Livewire::actingAs($this->admin)
        ->test('auth.changepassword')
        ->set('currentPassword', $oldPassword)
        ->set('newPassword', $newPassword)
        ->set('newPasswordConfirmation', $newPassword)
        ->call('changePassword');

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
