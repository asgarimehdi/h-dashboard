<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Unit;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(NotificationService::class);

class NotificationServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // --- send ---

    public function test_send_creates_notification_for_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $notification = NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'تست عنوان',
            body: 'متن تست'
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست عنوان',
            'body' => 'متن تست',
        ]);
        $this->assertInstanceOf(Notification::class, $notification);
    }

    public function test_send_returns_notification_model_with_uuid(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $notification = NotificationService::send(
            userId: $user->id,
            type: 'info',
            title: 'عنوان'
        );

        $this->assertNotNull($notification->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $notification->id);
    }

    public function test_send_with_optional_params(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $notification = NotificationService::send(
            userId: $user->id,
            type: 'ticket',
            title: 'تیکت جدید',
            body: 'یک تیکت جدید ایجاد شد',
            icon: 'o-ticket',
            color: 'text-warning',
            url: '/tickets/1',
            data: ['ticket_id' => 1]
        );

        $this->assertDatabaseHas('notifications', [
            'icon' => 'o-ticket',
            'color' => 'text-warning',
            'url' => '/tickets/1',
        ]);
        $this->assertEquals(['ticket_id' => 1], $notification->data);
    }

    public function test_send_defaults(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $notification = NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان پیش‌فرض'
        );

        $this->assertEquals('o-bell', $notification->icon);
        $this->assertEquals('text-info', $notification->color);
        $this->assertNull($notification->body);
        $this->assertNull($notification->url);
    }

    public function test_send_invalidates_bell_cache(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان'
        );

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
    }

    public function test_send_with_same_dedupe_key_creates_one_row(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $first = NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان',
            dedupeKey: 'event:42'
        );
        $second = NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان',
            dedupeKey: 'event:42'
        );

        $this->assertEquals(1, Notification::where('user_id', $user->id)->count());
        $this->assertSame((string) $first->id, (string) $second->id);
    }

    public function test_send_with_different_dedupe_keys_creates_two_rows(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        NotificationService::send(userId: $user->id, type: 'test', title: 'اول', dedupeKey: 'event:1');
        NotificationService::send(userId: $user->id, type: 'test', title: 'دوم', dedupeKey: 'event:2');

        $this->assertEquals(2, Notification::where('user_id', $user->id)->count());
    }

    public function test_send_without_dedupe_key_keeps_todays_behavior(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان');
        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان');

        $this->assertEquals(2, Notification::where('user_id', $user->id)->count());
    }

    public function test_empty_dedupe_key_is_treated_as_no_key(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: '');
        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: '');

        $this->assertEquals(2, Notification::where('user_id', $user->id)->count());
    }

    public function test_dedupe_key_longer_than_191_characters_is_rejected(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->expectException(\InvalidArgumentException::class);

        NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان',
            dedupeKey: str_repeat('a', 192)
        );
    }

    public function test_duplicate_send_does_not_overwrite_the_first_payload(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان اول', dedupeKey: 'event:7');
        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان دوم', dedupeKey: 'event:7');

        $notification = Notification::where('user_id', $user->id)->sole();
        $this->assertSame('عنوان اول', $notification->title);
    }

    public function test_duplicate_send_invalidates_bell_cache(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'event:9');

        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'event:9');

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
    }

    public function test_send_recovers_after_cache_forget_failure(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $attempt = 0;
        Cache::shouldReceive('forget')
            ->andReturnUsing(function () use (&$attempt) {
                $attempt++;

                if ($attempt === 1) {
                    throw new \RuntimeException('redis down');
                }

                return true;
            });

        try {
            NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'retry-key');
            $this->fail('First send should throw when cache invalidation fails.');
        } catch (\RuntimeException $e) {
            $this->assertSame('redis down', $e->getMessage());
        }

        NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'retry-key');

        $this->assertEquals(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_two_keyed_sends_inside_a_transaction_leave_it_usable(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        DB::transaction(function () use ($user) {
            NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'tx-key');
            NotificationService::send(userId: $user->id, type: 'test', title: 'عنوان', dedupeKey: 'tx-key');

            DB::select('select 1');
        });

        $this->assertEquals(1, Notification::where('user_id', $user->id)->count());
    }

    // --- notifyUnit ---

    public function test_notify_unit_sends_to_all_unit_members(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);

        // Create two users in same unit
        foreach (range(1, 2) as $i) {
            ['user' => $user] = $this->createUserWithUnit();
            $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        }

        NotificationService::notifyUnit($unit->id, 'test', 'اعلان واحد', 'متن تست', '/dashboard');

        $this->assertEquals(2, DB::table('notifications')->where('type', 'test')->count());
    }

    public function test_notify_unit_does_nothing_when_no_members(): void
    {
        $unit = Unit::create(['name' => 'واحد خالی']);

        NotificationService::notifyUnit($unit->id, 'test', 'عنوان');

        $this->assertEquals(0, DB::table('notifications')->count());
    }

    public function test_notify_unit_uses_ticket_icon_and_info_color(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $unit = $user->units()->first();

        NotificationService::notifyUnit($unit->id, 'test', 'عنوان');

        $notification = DB::table('notifications')->first();
        $this->assertEquals('o-ticket', $notification->icon);
        $this->assertEquals('text-info', $notification->color);
    }

    public function test_notify_unit_invalidates_bell_cache_for_recipients(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $unit = $user->units()->first();

        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        NotificationService::notifyUnit($unit->id, 'test', 'عنوان');

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
    }

    public function test_notify_unit_invalidates_bell_cache_after_commit(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $unit = $user->units()->first();

        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        DB::transaction(fn () => NotificationService::notifyUnit($unit->id, 'test', 'عنوان'));

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
        $this->assertEquals(1, DB::table('notifications')->where('user_id', $user->id)->count());
    }

    public function test_notify_unit_rollback_leaves_cache_untouched_and_writes_nothing(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $unit = $user->units()->first();

        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        try {
            DB::transaction(function () use ($unit) {
                NotificationService::notifyUnit($unit->id, 'test', 'عنوان');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull(Cache::get("notifications:user:{$user->id}"));
        $this->assertEquals(0, DB::table('notifications')->count());
    }
}
