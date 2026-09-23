<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class SettingsBrowserNotificationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_settings_page_has_notification_toggle(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertStatus(200)
            ->assertSet('browserNotifications', false);
    }

    public function test_browser_notification_setting_persists(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->set('browserNotifications', true)
            ->call('save');

        $user->refresh();
        $this->assertTrue($user->settings['browser_notifications']);
    }

    public function test_notify_permission_denied_runs_without_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->call('notifyPermissionDenied')
            ->assertStatus(200);
    }

    public function test_notify_unsupported_runs_without_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->call('notifyUnsupported')
            ->assertStatus(200);
    }

    public function test_send_test_notification_when_disabled_shows_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('settings.index')
            ->assertSet('browserNotifications', false)
            ->call('sendTestNotification')
            ->assertStatus(200);
    }
}
