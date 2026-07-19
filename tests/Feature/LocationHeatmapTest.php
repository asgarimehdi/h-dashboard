<?php

namespace Tests\Feature;

use App\Models\LocationLog;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

beforeEach(function () {
    Session::flush();
});

test('returns heatmap data for all users', function () {
    $user = User::factory()->create();
    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.6892,
        'longitude' => 51.3890,
    ]);
    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.7000,
        'longitude' => 51.4000,
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/location');

    $response->assertStatus(200)
        ->assertJsonStructure([
            '*' => ['lat', 'lng', 'userId', 'userName', 'time'],
        ]);

    expect($response->json())->toHaveCount(2);
});

test('scopes heatmap data by unit', function () {
    $unit = Unit::factory()->create();
    $user = User::factory()->create();
    $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
    Session::put('current_unit_id', $unit->id);

    $otherUser = User::factory()->create();

    LocationLog::create(['user_id' => $user->id, 'latitude' => 35.5, 'longitude' => 51.5]);
    LocationLog::create(['user_id' => $otherUser->id, 'latitude' => 36.0, 'longitude' => 52.0]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/location?unit_id={$unit->id}");

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(1);
    expect($response->json()[0]['userId'])->toBe($user->id);
});

test('filters by date range', function () {
    $user = User::factory()->create();

    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.0,
        'longitude' => 51.0,
        'created_at' => '2026-07-01 10:00:00',
    ]);
    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.5,
        'longitude' => 51.5,
        'created_at' => '2026-07-20 10:00:00',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/location?from=2026-07-15&to=2026-07-25');

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(1);
});

test('returns latest user location', function () {
    $user = User::factory()->create();

    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.0,
        'longitude' => 51.0,
        'created_at' => '2026-07-01 10:00:00',
    ]);
    LocationLog::create([
        'user_id' => $user->id,
        'latitude' => 35.5,
        'longitude' => 51.5,
        'created_at' => '2026-07-20 12:00:00',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/location/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonStructure(['latitude', 'longitude', 'logged_at'])
        ->assertJson([
            'latitude' => 35.5,
            'longitude' => 51.5,
        ]);
});

test('returns 404 when no location found', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/location/{$user->id}");

    $response->assertStatus(404)
        ->assertJson(['message' => 'No location found']);
});