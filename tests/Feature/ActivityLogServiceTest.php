<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function log_creates_record_with_user_id(): void
    {
        $user = User::factory()->create();

        $log = ActivityLogService::created((object) ['id' => 1], 'تست');

        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals('created', $log->type);
        $this->assertEquals('تست', $log->description);
    }

    public function log_stores_description_safely(): void
    {
        $log = ActivityLogService::log('created', null, '<script>alert(1)</script>');

        $this->assertEquals('<script>alert(1)</script>', $log->description);
        // Note: the admin view must escape description when rendering — current behavior is documented
    }

    public function updated_accepts_old_and_new_values_array(): void
    {
        $user = User::factory()->create();

        $log = ActivityLogService::updated(
            (object) ['id' => 1],
            ['status' => 'created'],
            ['status' => 'completed'],
            'Status changed'
        );

        $this->assertEquals(['status' => 'created'], $log->old_values);
        $this->assertEquals(['status' => 'completed'], $log->new_values);
        $this->assertEquals('Status changed', $log->description);
    }

    public function login_records_login_type(): void
    {
        $log = ActivityLogService::login();

        $this->assertEquals('login', $log->type);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
    }

    public function logout_records_logout_type(): void
    {
        $log = ActivityLogService::logout();

        $this->assertEquals('logout', $log->type);
    }

    public function ip_address_is_recorded(): void
    {
        $log = ActivityLogService::log('test', null, 'Test');

        $this->assertNotNull($log->ip_address);
    }

    public function user_agent_is_recorded(): void
    {
        $log = ActivityLogService::log('test', null, 'Test');

        $this->assertNotNull($log->user_agent);
    }

    public function subject_polymorphic_relation_works(): void
    {
        $user = User::factory()->create();

        $log = ActivityLogService::created($user, 'User created');

        $this->assertEquals(User::class, $log->subject_type);
        $this->assertEquals($user->id, $log->subject_id);
    }
}
