<?php

namespace Tests\Unit;

use App\Models\LocationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationLogTest extends TestCase
{
    use RefreshDatabase;

    public function it_has_user_relationship(): void
    {
        $user = User::factory()->create();
        $log = LocationLog::create([
            'user_id' => $user->id,
            'latitude' => 35.6892,
            'longitude' => 51.3890,
        ]);

        $this->assertInstanceOf(User::class, $log->user);
        $this->assertEquals($user->id, $log->user->id);
    }
}