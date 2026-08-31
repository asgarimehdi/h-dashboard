<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\ActivityLogService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

covers(\App\Services\ActivityLogService::class);

class ActivityLogServiceTest extends TestCase
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
        $this->unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $this->unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    // --- log ---

    public function test_log_creates_activity_log_entry(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::log('test_action', description: 'تست لاگ');

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'type' => 'test_action',
            'description' => 'تست لاگ',
        ]);
    }

    public function test_log_records_subject_when_provided(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه تست', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::log('created', $todo, 'ایجاد وظیفه');

        $this->assertEquals('App\Models\Todo', $log->subject_type);
        $this->assertEquals($todo->id, $log->subject_id);
    }

    public function test_log_records_old_and_new_values(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::log(
            'updated',
            description: 'ویرایش',
            oldValues: ['name' => 'قدیم'],
            newValues: ['name' => 'جدید']
        );

        $this->assertEquals(['name' => 'قدیم'], $log->old_values);
        $this->assertEquals(['name' => 'جدید'], $log->new_values);
    }

    public function test_log_records_ip_and_user_agent(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $request = Request::create('/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.100',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $log = ActivityLogService::log('test', request: $request);

        $this->assertEquals('192.168.1.100', $log->ip_address);
        $this->assertEquals('TestAgent/1.0', $log->user_agent);
    }

    // --- created ---

    public function test_created_logs_creation_action(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه جدید', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::created($todo);

        $this->assertEquals('created', $log->type);
        $this->assertStringContainsString('ایجاد', $log->description);
    }

    public function test_created_uses_custom_description(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::created($todo, 'توضیح سفارشی');

        $this->assertEquals('توضیح سفارشی', $log->description);
    }

    // --- updated ---

    public function test_updated_logs_update_action_with_changes(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'عنوان اصلی', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::updated($todo, ['title' => 'عنوان اصلی'], ['title' => 'عنوان جدید']);

        $this->assertEquals('updated', $log->type);
        $this->assertEquals(['title' => 'عنوان اصلی'], $log->old_values);
        $this->assertEquals(['title' => 'عنوان جدید'], $log->new_values);
    }

    // --- deleted ---

    public function test_deleted_logs_deletion_action(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه حذف', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::deleted($todo);

        $this->assertEquals('deleted', $log->type);
        $this->assertStringContainsString('حذف', $log->description);
    }

    public function test_deleted_uses_custom_description(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::deleted($todo, 'حذف اجباری');

        $this->assertEquals('حذف اجباری', $log->description);
    }

    // --- login / logout ---

    public function test_login_creates_login_log(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::login();

        $this->assertEquals('login', $log->type);
        $this->assertStringContainsString('ورود', $log->description);
        $this->assertNull($log->subject_id);
    }

    public function test_logout_creates_logout_log(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::logout();

        $this->assertEquals('logout', $log->type);
        $this->assertStringContainsString('خروج', $log->description);
        $this->assertNull($log->subject_id);
    }

    public function test_login_with_custom_description(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::login('ورود از موبایل');

        $this->assertEquals('ورود از موبایل', $log->description);
    }
}
