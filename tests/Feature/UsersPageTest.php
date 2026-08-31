<?php

namespace Tests\Feature;

use App\Models\Notification;
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

/** @covers \App\Models\User */
class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);
    }

    protected function createAdminUser(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'مدیر', 'l_name' => 'کل',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user->givePermissionTo('manage_users');

        return $user;
    }

    // --- Users page ---

    public function test_users_page_loads_for_authorized_user(): void
    {
        $user = $this->createAdminUser();
        Session::put('current_unit_id', 1);

        $this->actingAs($user);
        Livewire::test('users.index')
            ->assertStatus(200);
    }

    public function test_users_page_returns_403_for_unauthorized_user(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'کاربر', 'l_name' => 'عادی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', 1);

        $this->actingAs($user);
        $this->get('/users')->assertStatus(403);
    }

    public function test_guest_redirected_from_users(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    // --- Notifications Bell ---

    public function test_notifications_bell_shows_unread_count(): void
    {
        $user = $this->createAdminUser();
        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'اعلان تست',
        ]);

        Session::put('current_unit_id', 1);
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertStatus(200);
    }

    public function test_notifications_bell_with_no_notifications(): void
    {
        $user = $this->createAdminUser();

        Session::put('current_unit_id', 1);
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertStatus(200);
    }

    // --- Login Livewire component ---

    public function test_login_page_loads(): void
    {
        Livewire::test('auth.login')
            ->assertStatus(200);
    }

    public function test_login_with_valid_credentials_redirects(): void
    {
        $user = $this->createAdminUser();

        Livewire::test('auth.login')
            ->set('n_code', $user->n_code)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();
    }

    public function test_login_with_invalid_credentials_shows_error(): void
    {
        Livewire::test('auth.login')
            ->set('n_code', '9999999999')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors(['n_code']);
    }

    // --- Todos page ---

    public function test_todo_page_loads_for_authorized_user(): void
    {
        $user = $this->createAdminUser();
        $user->givePermissionTo('calendar');
        Session::put('current_unit_id', 1);

        $this->actingAs($user);
        Livewire::test('todo.todo')
            ->assertStatus(200);
    }

    public function test_todo_page_returns_403_for_unauthorized_user(): void
    {
        $user = $this->createAdminUser();
        // No calendar permission
        Session::put('current_unit_id', 1);

        $this->actingAs($user);
        $this->get('/todo')->assertStatus(403);
    }

    // --- Kargozini pages ---

    public function test_kargozini_pages_load_for_authorized_user(): void
    {
        $user = $this->createAdminUser();
        $user->givePermissionTo('kargozini');
        Session::put('current_unit_id', 1);

        $this->actingAs($user);

        Livewire::test('kargozini.person')->assertStatus(200);
        Livewire::test('kargozini.semat')->assertStatus(200);
        Livewire::test('kargozini.tahsil')->assertStatus(200);
        Livewire::test('kargozini.estekhdam')->assertStatus(200);
        Livewire::test('kargozini.radif')->assertStatus(200);
    }

    public function test_kargozini_pages_returns_403_for_unauthorized_user(): void
    {
        $user = $this->createAdminUser();
        // No kargozini permission
        Session::put('current_unit_id', 1);

        $this->actingAs($user);
        $this->get('/kargozini/persons')->assertStatus(403);
        $this->get('/kargozini/semats')->assertStatus(403);
        $this->get('/kargozini/tahsils')->assertStatus(403);
        $this->get('/kargozini/estekhdams')->assertStatus(403);
        $this->get('/kargozini/radifs')->assertStatus(403);
    }

    // --- Reports pages ---

    public function test_reports_pages_load_for_authorized_user(): void
    {
        $user = $this->createAdminUser();
        Session::put('current_unit_id', 1);

        $this->actingAs($user);

        Livewire::test('reports.advanced')->assertStatus(200);
        Livewire::test('reports.units')->assertStatus(200);
        Livewire::test('reports.todos')->assertStatus(200);
    }
}
