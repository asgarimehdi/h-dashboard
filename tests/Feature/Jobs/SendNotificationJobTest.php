<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_notification(): void
    {
        $user = User::factory()->create();

        SendNotificationJob::dispatch(
            $user->id,
            'mention',
            'You were mentioned',
            'Mention body',
            'at-sign',
            'text-blue-500',
            '/tickets/1'
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'mention',
            'title' => 'You were mentioned',
            'body' => 'Mention body',
        ]);
    }

    public function test_job_logs_on_failure(): void
    {
        $user = User::factory()->create();

        $job = new SendNotificationJob(
            $user->id,
            'mention',
            'You were mentioned',
            null,
            'o-bell',
            'text-info',
            null,
            null
        );

        Log::shouldReceive('error')
            ->once()
            ->with('SendNotificationJob failed', \Mockery::on(function ($context) use ($user) {
                return $context['user_id'] === $user->id
                    && $context['type'] === 'mention'
                    && $context['error'] === 'Database connection lost';
            }));

        $exception = new \RuntimeException('Database connection lost');
        $job->failed($exception);
    }
}
