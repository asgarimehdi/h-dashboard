<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\Todo;
use App\Services\ActivityLogService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(ActivityLogService::class);

class ActivityLogServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // --- log ---

    public function test_log_creates_activity_log_entry(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه تست', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::log('created', $todo, 'ایجاد وظیفه');

        $this->assertEquals('App\Models\Todo', $log->subject_type);
        $this->assertEquals($todo->id, $log->subject_id);
    }

    public function test_log_records_old_and_new_values(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه جدید', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::created($todo);

        $this->assertEquals('created', $log->type);
        $this->assertStringContainsString('ایجاد', $log->description);
    }

    public function test_created_uses_custom_description(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::created($todo, 'توضیح سفارشی');

        $this->assertEquals('توضیح سفارشی', $log->description);
    }

    // --- updated ---

    public function test_updated_logs_update_action_with_changes(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
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
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه حذف', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::deleted($todo);

        $this->assertEquals('deleted', $log->type);
        $this->assertStringContainsString('حذف', $log->description);
    }

    public function test_deleted_uses_custom_description(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'وظیفه', 'unit_id' => $this->unit->id]);

        $log = ActivityLogService::deleted($todo, 'حذف اجباری');

        $this->assertEquals('حذف اجباری', $log->description);
    }

    // --- login / logout ---

    public function test_login_creates_login_log(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::login();

        $this->assertEquals('login', $log->type);
        $this->assertStringContainsString('ورود', $log->description);
        $this->assertNull($log->subject_id);
    }

    public function test_logout_creates_logout_log(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::logout();

        $this->assertEquals('logout', $log->type);
        $this->assertStringContainsString('خروج', $log->description);
        $this->assertNull($log->subject_id);
    }

    public function test_login_with_custom_description(): void
    {
        ['user' => $user, 'unit' => $this->unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::login('ورود از موبایل');

        $this->assertEquals('ورود از موبایل', $log->description);
    }

    // --- denylist (#1400) ---

    private const DENIED_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'api_token',
        'two_factor_secret', 'two_factor_recovery_codes',
        'n_code', 'kod_melli', 'phone', 'email', 'birth_date', 'hire_date',
    ];

    public function test_write_strips_every_denied_key_from_both_value_sets(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $old = array_fill_keys(self::DENIED_KEYS, 'old');
        $new = array_fill_keys(self::DENIED_KEYS, 'new');
        $old['status'] = 'active';
        $new['status'] = 'inactive';

        $log = ActivityLogService::log('updated', oldValues: $old, newValues: $new);

        // `fresh()` is the point: the returned model was built from the
        // sanitized array, only the reload proves what reached the database.
        $this->assertSame(['status' => 'active'], $log->fresh()->old_values);
        $this->assertSame(['status' => 'inactive'], $log->fresh()->new_values);

        $stored = (string) DB::table('activity_logs')->where('id', $log->id)->value('old_values')
            .(string) DB::table('activity_logs')->where('id', $log->id)->value('new_values');
        foreach (self::DENIED_KEYS as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $stored);
        }

        // A payload of denied keys only is an empty array, never null.
        $empty = ActivityLogService::log('updated', oldValues: ['password' => 'x'], newValues: ['password' => 'y']);
        $this->assertSame([], $empty->fresh()->old_values);
        $this->assertSame([], $empty->fresh()->new_values);
    }

    public function test_denylist_applies_recursively_to_nested_arrays(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLogService::log('updated', oldValues: [
            'settings' => ['theme' => 'light', 'password' => 'nested'],
            'rows' => [['phone' => '09120000000', 'title' => 'a'], ['title' => 'b']],
            'status' => 'open',
        ]);

        $this->assertSame([
            'settings' => ['theme' => 'light'],
            'rows' => [['title' => 'a'], ['title' => 'b']],
            'status' => 'open',
        ], $log->fresh()->old_values);
    }

    public function test_updated_wrapper_inherits_the_denylist(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create(['title' => 'a', 'unit_id' => $unit->id]);

        $log = ActivityLogService::updated(
            $todo,
            ['password' => 'old', 'title' => 'a'],
            ['remember_token' => 'new', 'title' => 'b'],
        );

        $this->assertSame(['title' => 'a'], $log->fresh()->old_values);
        $this->assertSame(['title' => 'b'], $log->fresh()->new_values);
    }

    public function test_raw_model_attribute_array_loses_denied_keys_and_keeps_the_audited_ones(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $person = Person::factory()->create([
            'u_id' => $unit->id,
            'phone' => '09120000000',
            'birth_date' => '1990-01-01',
            'hire_date' => '2015-01-01',
        ]);

        // Fixture guard: the "raw array" really does carry the identifiers.
        $raw = $person->getAttributes();
        $this->assertArrayHasKey('n_code', $raw);
        $this->assertArrayHasKey('phone', $raw);

        $log = ActivityLogService::log('updated', $person, 'x', $raw, $raw);

        foreach (['n_code', 'phone', 'birth_date', 'hire_date'] as $key) {
            $this->assertArrayNotHasKey($key, $log->fresh()->old_values);
            $this->assertArrayNotHasKey($key, $log->fresh()->new_values);
        }

        // The audit survives: the non-denied columns are still there.
        $this->assertArrayHasKey('f_name', $log->fresh()->old_values);
    }

    public function test_user_attribute_array_never_stores_the_hash_or_token(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        // `toArray()` hides these; `getAttributes()` does not — that is the
        // diff path a future caller would actually reach for.
        $raw = $user->getAttributes();
        $this->assertArrayHasKey('password', $raw);

        $log = ActivityLogService::log('updated', $user, 'x', $raw, $raw);

        $this->assertArrayNotHasKey('password', $log->fresh()->old_values);
        $this->assertArrayNotHasKey('remember_token', $log->fresh()->new_values);

        $stored = (string) DB::table('activity_logs')->where('id', $log->id)->value('old_values');
        $this->assertStringNotContainsString((string) $raw['password'], $stored);
        $this->assertStringNotContainsString((string) $raw['remember_token'], $stored);
    }
}
