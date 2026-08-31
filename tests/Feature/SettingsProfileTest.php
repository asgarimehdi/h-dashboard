<?php

namespace Tests\Feature;

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
class SettingsProfileTest extends TestCase
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

    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return $user;
    }

    // ==================== Settings Page ====================

    public function test_settings_page_loads(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertStatus(200);
    }

    public function test_settings_mount_loads_user_settings(): void
    {
        $user = $this->createUserWithUnit();
        $user->update(['settings' => [
            'email_notifications' => false,
            'browser_notifications' => true,
            'dashboard_refresh' => 30,
            'compact_mode' => true,
        ]]);
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('emailNotifications', false)
            ->assertSet('browserNotifications', true)
            ->assertSet('dashboardRefresh', 30)
            ->assertSet('compactMode', true);
    }

    public function test_settings_mount_defaults_when_no_settings(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('emailNotifications', true)
            ->assertSet('browserNotifications', false)
            ->assertSet('dashboardRefresh', 0)
            ->assertSet('compactMode', false);
    }

    public function test_settings_save_persists_to_database(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('emailNotifications', false)
            ->set('browserNotifications', true)
            ->set('dashboardRefresh', 60)
            ->set('compactMode', true)
            ->call('save');

        $user->refresh();
        $this->assertEquals(false, $user->settings['email_notifications']);
        $this->assertEquals(true, $user->settings['browser_notifications']);
        $this->assertEquals(60, $user->settings['dashboard_refresh']);
        $this->assertEquals(true, $user->settings['compact_mode']);
    }

    public function test_guest_redirected_from_settings(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    // ==================== Profile Page ====================

    public function test_profile_page_loads(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertStatus(200);
    }

    public function test_profile_mount_loads_user_data(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertSet('user.id', $user->id);
    }

    public function test_profile_shows_ticket_stats(): void
    {
        $user = $this->createUserWithUnit();
        $unit = Unit::first();

        // Create tickets for this user
        \App\Models\Ticket::create([
            'ticket_code' => 'TKT-001', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست', 'content' => 'متن', 'priority' => 'normal', 'status' => 'created',
        ]);
        \App\Models\Ticket::create([
            'ticket_code' => 'TKT-002', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست ۲', 'content' => 'متن', 'priority' => 'normal', 'status' => 'completed',
        ]);

        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertSet('totalTickets', 2)
            ->assertSet('completedTickets', 1)
            ->assertSet('pendingTickets', 1);
    }

    public function test_profile_shows_todo_stats(): void
    {
        $user = $this->createUserWithUnit();
        $unit = Unit::first();

        \App\Models\Todo::factory()->completed()->create(['unit_id' => $unit->id]);
        \App\Models\Todo::factory()->pending()->create(['unit_id' => $unit->id]);

        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertSet('totalTodos', 2)
            ->assertSet('completedTodos', 1);
    }

    public function test_guest_redirected_from_profile(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }
}
