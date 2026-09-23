<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Notification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(NotificationController::class);

class NotificationApiTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    public function test_user_can_list_notifications(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Test Notification',
            'body' => 'Test body',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications');
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_get_unread_count(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Unread',
            'body' => 'Body',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Read',
            'body' => 'Body',
            'is_read' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications/unread-count');
        $response->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Test',
            'body' => 'Body',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->postJson("/api/notifications/{$notification->id}/read");
        $response->assertOk()
            ->assertJson(['success' => true]);

        $notification->refresh();
        $this->assertTrue($notification->is_read);
    }

    public function test_user_cannot_mark_other_users_notification_as_read(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        // Create another user
        [$otherUser] = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $otherUser->id,
            'type' => 'test',
            'title' => 'Other user notification',
            'body' => 'Body',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->postJson("/api/notifications/{$notification->id}/read");
        $response->assertStatus(404);
    }

    public function test_user_can_mark_all_as_read(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Notification 1',
            'body' => 'Body',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Notification 2',
            'body' => 'Body',
            'is_read' => false,
        ]);

        $response = $this->actingAs($user)->postJson('/api/notifications/read-all');
        $response->assertOk()
            ->assertJson(['success' => true]);

        $unreadCount = Notification::where('user_id', $user->id)->where('is_read', false)->count();
        $this->assertEquals(0, $unreadCount);
    }
}
