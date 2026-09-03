<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(Notification::class);

class NotificationModelTest extends TestCase
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

        return $user;
    }

    // --- UUID auto-generation ---

    public function test_notification_gets_uuid_on_create(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
        ]);

        $this->assertNotNull($notification->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $notification->id);
    }

    public function test_notification_is_not_auto_incrementing(): void
    {
        $notification = new Notification;

        $this->assertFalse($notification->getIncrementing());
        $this->assertEquals('string', $notification->getKeyType());
    }

    // --- Belongs to User ---

    public function test_notification_belongs_to_user(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
        ]);

        $this->assertNotNull($notification->user);
        $this->assertEquals($user->id, $notification->user->id);
    }

    // --- markAsRead ---

    public function test_mark_as_read_sets_is_read_and_read_at(): void
    {
        $user = $this->createUserWithUnit();
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
            'is_read' => false,
        ]);

        $notification->markAsRead();
        $notification->refresh();

        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    // --- markAllAsRead ---

    public function test_mark_all_as_read_marks_all_unread_for_current_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create 3 unread notifications
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => '۱', 'is_read' => false]);
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => '۲', 'is_read' => false]);
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => '۳', 'is_read' => false]);

        Notification::markAllAsRead();

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'is_read' => true]);
        $this->assertEquals(0, Notification::where('user_id', $user->id)->where('is_read', false)->count());
    }

    public function test_mark_all_as_read_does_not_affect_other_users(): void
    {
        $user1 = $this->createUserWithUnit();
        $this->actingAs($user1);

        $nCode2 = (string) fake()->unique()->numerify('##########');
        $unit = Unit::first();
        Person::create([
            'n_code' => $nCode2, 'f_name' => 'کاربر دوم', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user2 = User::create(['n_code' => $nCode2, 'password' => Hash::make('password')]);

        Notification::create(['user_id' => $user1->id, 'type' => 'test', 'title' => '۱', 'is_read' => false]);
        Notification::create(['user_id' => $user2->id, 'type' => 'test', 'title' => '۲', 'is_read' => false]);

        Notification::markAllAsRead();

        // User1's notification should be read
        $this->assertEquals(0, Notification::where('user_id', $user1->id)->where('is_read', false)->count());
        // User2's notification should still be unread
        $this->assertEquals(1, Notification::where('user_id', $user2->id)->where('is_read', false)->count());
    }

    public function test_mark_all_as_read_does_not_mark_already_read(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'خوانده شده', 'is_read' => true]);
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'خوانده نشده', 'is_read' => false]);

        Notification::markAllAsRead();

        $this->assertEquals(2, Notification::where('user_id', $user->id)->where('is_read', true)->count());
    }

    // --- Fillable attributes ---

    public function test_notification_fillable_attributes(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'ticket_created',
            'title' => 'تیکت جدید',
            'body' => 'یک تیکت ایجاد شد',
            'icon' => 'o-ticket',
            'color' => 'text-success',
            'url' => '/tickets/1',
            'data' => ['ticket_id' => 1],
        ]);

        $this->assertEquals('ticket_created', $notification->type);
        $this->assertEquals('تیکت جدید', $notification->title);
        $this->assertEquals('یک تیکت ایجاد شد', $notification->body);
        $this->assertEquals('o-ticket', $notification->icon);
        $this->assertEquals('text-success', $notification->color);
        $this->assertEquals('/tickets/1', $notification->url);
        $this->assertEquals(['ticket_id' => 1], $notification->data);
    }

    // --- Casts ---

    public function test_notification_data_is_cast_to_array(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
            'data' => ['key' => 'value'],
        ]);

        $this->assertIsArray($notification->data);
        $this->assertEquals('value', $notification->data['key']);
    }

    public function test_notification_is_read_is_cast_to_boolean(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
            'is_read' => false,
        ]);

        $this->assertIsBool($notification->is_read);
        $this->assertFalse($notification->is_read);
    }

    public function test_notification_read_at_is_cast_to_datetime(): void
    {
        $user = $this->createUserWithUnit();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'تست',
            'read_at' => now(),
        ]);

        $this->assertInstanceOf(Carbon::class, $notification->read_at);
    }
}
