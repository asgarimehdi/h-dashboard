<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(NotificationService::class);

class NotificationServiceTest extends TestCase
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

    // --- send ---

    public function test_send_creates_notification_for_user(): void
    {
        $user = $this->createUserWithUnit();

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
        $user = $this->createUserWithUnit();

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
        $user = $this->createUserWithUnit();

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
        $user = $this->createUserWithUnit();

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
        $user = $this->createUserWithUnit();
        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        NotificationService::send(
            userId: $user->id,
            type: 'test',
            title: 'عنوان'
        );

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
    }

    // --- notifyUnit ---

    public function test_notify_unit_sends_to_all_unit_members(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);

        // Create two users in same unit
        foreach (range(1, 2) as $i) {
            $nCode = (string) fake()->unique()->numerify('##########');
            Person::create([
                'n_code' => $nCode, 'f_name' => "کاربر {$i}", 'l_name' => 'تست',
                't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
            ]);
            $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
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
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

        NotificationService::notifyUnit($unit->id, 'test', 'عنوان');

        $notification = DB::table('notifications')->first();
        $this->assertEquals('o-ticket', $notification->icon);
        $this->assertEquals('text-info', $notification->color);
    }

    public function test_notify_unit_invalidates_bell_cache_for_recipients(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        Cache::put("notifications:user:{$user->id}", ['cached' => true]);

        NotificationService::notifyUnit($unit->id, 'test', 'عنوان');

        $this->assertNull(Cache::get("notifications:user:{$user->id}"));
    }
}
