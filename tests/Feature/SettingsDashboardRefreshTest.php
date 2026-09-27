<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class SettingsDashboardRefreshTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_dashboard_refresh_setting_persists(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('dashboardRefresh', 30)
            ->call('save');

        $user->refresh();
        $this->assertEquals(30, $user->settings['dashboard_refresh']);
    }

    public function test_dashboard_reads_refresh_setting(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->settings = ['dashboard_refresh' => 60];
        $user->save();
        $this->actingAs($user);

        Livewire::test('dashboard')
            ->assertSet('refreshInterval', 60);
    }
}
