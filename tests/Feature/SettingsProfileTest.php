<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class SettingsProfileTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Settings Page ====================

    public function test_settings_page_loads(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertStatus(200);
    }

    public function test_settings_mount_loads_user_settings(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->update(['settings' => [
            'browser_notifications' => true,
            'dashboard_refresh' => 30,
            'compact_mode' => true,
        ]]);
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('browserNotifications', true)
            ->assertSet('dashboardRefresh', 30)
            ->assertSet('compactMode', true);
    }

    public function test_settings_mount_defaults_when_no_settings(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('browserNotifications', false)
            ->assertSet('dashboardRefresh', 0)
            ->assertSet('compactMode', false);
    }

    public function test_settings_save_persists_to_database(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('browserNotifications', true)
            ->set('dashboardRefresh', 60)
            ->set('compactMode', true)
            ->call('save');

        $user->refresh();
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
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertStatus(200);
    }

    public function test_profile_mount_loads_user_data(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertSet('user.id', $user->id);
    }

    public function test_profile_shows_ticket_stats(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $unit = Unit::first();

        Ticket::create([
            'ticket_code' => 'TKT-001', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست', 'content' => 'متن', 'priority' => 'normal', 'status' => 'created',
        ]);
        Ticket::create([
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
        ['user' => $user] = $this->createUserWithUnit();
        $unit = Unit::first();

        Todo::factory()->completed()->create(['unit_id' => $unit->id]);
        Todo::factory()->pending()->create(['unit_id' => $unit->id]);

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
