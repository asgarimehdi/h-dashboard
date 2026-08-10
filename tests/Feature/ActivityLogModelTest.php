<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivityLogModelTest extends TestCase
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

    public function test_activity_log_belongs_to_user(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'test',
            'description' => 'تست',
        ]);

        $this->assertNotNull($log->user);
        $this->assertEquals($user->id, $log->user->id);
    }

    public function test_activity_log_subject_morph_to(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $todo = Todo::factory()->create();

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'created',
            'subject_type' => Todo::class,
            'subject_id' => $todo->id,
            'description' => 'ایجاد وظیفه',
        ]);

        $this->assertNotNull($log->subject);
        $this->assertInstanceOf(Todo::class, $log->subject);
        $this->assertEquals($todo->id, $log->subject->id);
    }

    public function test_activity_log_old_values_cast_to_array(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'updated',
            'description' => 'ویرایش',
            'old_values' => ['name' => 'قدیم', 'count' => 1],
            'new_values' => ['name' => 'جدید', 'count' => 2],
        ]);

        $this->assertIsArray($log->old_values);
        $this->assertEquals('قدیم', $log->old_values['name']);
        $this->assertEquals(1, $log->old_values['count']);
    }

    public function test_activity_log_new_values_cast_to_array(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'updated',
            'description' => 'ویرایش',
            'new_values' => ['name' => 'جدید'],
        ]);

        $this->assertIsArray($log->new_values);
        $this->assertEquals('جدید', $log->new_values['name']);
    }

    public function test_activity_log_null_values_are_null(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'login',
            'description' => 'ورود',
        ]);

        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
    }

    public function test_activity_log_fillable(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'type' => 'custom',
            'description' => 'توضیح',
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Test/1.0',
        ]);

        $this->assertEquals('custom', $log->type);
        $this->assertEquals('توضیح', $log->description);
        $this->assertEquals('10.0.0.1', $log->ip_address);
        $this->assertEquals('Test/1.0', $log->user_agent);
    }
}
