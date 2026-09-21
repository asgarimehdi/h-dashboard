<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

covers(NotificationController::class);

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        // Create required reference data
        $tId = \DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = \DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = \DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = \DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::create(['name' => 'Test Unit']);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);
        $this->seed(PermissionSeeder::class);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    public function test_user_can_list_notifications(): void
    {
        $data = $this->createUserWithUnit();
        $user = $data['user'];

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
        $data = $this->createUserWithUnit();
        $user = $data['user'];

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
        $data = $this->createUserWithUnit();
        $user = $data['user'];

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
        $data = $this->createUserWithUnit();
        $user = $data['user'];

        // Create another user with proper Person record
        $tId = \DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = \DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = \DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = \DB::table('radifs')->insertGetId(['name' => 'Test']);

        $otherNCode = (string) fake()->unique()->numerify('##########');
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        Person::create(['n_code' => $otherNCode, 'f_name' => 'Other', 'l_name' => 'User', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $otherUnit->id]);
        $otherUser = User::create(['n_code' => $otherNCode, 'password' => Hash::make('password')]);

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
        $data = $this->createUserWithUnit();
        $user = $data['user'];

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
