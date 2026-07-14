<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Unit;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function send_creates_notification_with_uuid(): void
    {
        $user = User::factory()->create();

        $notification = NotificationService::send(
            $user->id,
            'test_type',
            'Test Title',
            'Test body'
        );

        $this->assertNotNull($notification->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $notification->id
        );
        $this->assertEquals($user->id, $notification->user_id);
        $this->assertEquals('test_type', $notification->type);
        $this->assertEquals('Test Title', $notification->title);
        $this->assertEquals('Test body', $notification->body);
    }

    public function send_stores_all_fields(): void
    {
        $user = User::factory()->create();
        $data = ['key' => 'value'];

        $notification = NotificationService::send(
            $user->id,
            'ticket_created',
            'تیکت جدید',
            'Test body',
            'o-ticket',
            'text-info',
            '/tickets/inbox',
            $data
        );

        $this->assertEquals('o-ticket', $notification->icon);
        $this->assertEquals('text-info', $notification->color);
        $this->assertEquals('/tickets/inbox', $notification->url);
        $this->assertEquals($data, $notification->data);
        $this->assertFalse($notification->is_read);
    }

    public function notifyUnit_creates_notification_for_each_unit_user(): void
    {
        $unit = Unit::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user1->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        $user2->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => false]);

        NotificationService::notifyUnit($unit->id, 'test', 'Title', 'Body');

        $this->assertDatabaseHas('notifications', ['user_id' => $user1->id, 'title' => 'Title']);
        $this->assertDatabaseHas('notifications', ['user_id' => $user2->id, 'title' => 'Title']);
    }

    public function notifyUnit_does_nothing_for_unit_with_no_users(): void
    {
        $unit = Unit::factory()->create();

        $countBefore = Notification::count();
        NotificationService::notifyUnit($unit->id, 'test', 'Title', 'Body');
        $countAfter = Notification::count();

        $this->assertEquals($countBefore, $countAfter);
    }

    public function markAsRead_works(): void
    {
        $user = User::factory()->create();
        $notification = NotificationService::send($user->id, 'test', 'Title');

        $this->assertFalse($notification->fresh()->is_read);
        $notification->markAsRead();
        $this->assertTrue($notification->fresh()->is_read);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function markAllAsRead_works(): void
    {
        $user = User::factory()->create();
        NotificationService::send($user->id, 'test', 'Title 1');
        NotificationService::send($user->id, 'test', 'Title 2');

        Notification::markAllAsRead();

        $this->assertEquals(0, Notification::where('user_id', $user->id)->where('is_read', false)->count());
    }
}
