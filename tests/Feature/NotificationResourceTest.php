<?php

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

covers(NotificationResource::class);

uses(TestCase::class, RefreshDatabase::class);

it('transforms all expected fields', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => 'ticket',
        'title' => 'New ticket assigned',
        'body' => 'You have a new ticket',
        'icon' => 'ticket-icon',
        'color' => '#ff0000',
        'url' => '/tickets/1',
        'data' => ['ticket_id' => 1],
        'is_read' => false,
        'read_at' => null,
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKeys([
        'id', 'type', 'title', 'body', 'icon', 'color', 'url', 'data',
        'is_read', 'read_at', 'created_at',
    ]);
    expect($array['type'])->toBe('ticket');
    expect($array['title'])->toBe('New ticket assigned');
    expect($array['is_read'])->toBeFalse();
    expect($array['read_at'])->toBeNull();
});

it('casts read_at to ISO string when present', function () {
    $notification = Notification::factory()->create([
        'read_at' => now(),
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request);

    expect($array['read_at'])->toBeString();
    // Must be valid ISO 8601
    expect(strtotime($array['read_at']))->not->toBeFalse();
});

it('casts created_at to ISO string', function () {
    $notification = Notification::factory()->create();

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request);

    expect($array['created_at'])->toBeString();
    // Must be valid ISO 8601 (e.g. 2026-09-22T10:30:00.000000Z)
    expect(strtotime($array['created_at']))->not->toBeFalse();
});

it('passes through data array', function () {
    $notification = Notification::factory()->create([
        'data' => ['custom_key' => 'custom_value', 'nested' => ['a' => 1]],
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request);

    expect($array['data'])->toBe(['custom_key' => 'custom_value', 'nested' => ['a' => 1]]);
});

it('returns null for nullable fields when not set', function () {
    $notification = Notification::factory()->create([
        'icon' => 'o-bell', // default
        'color' => 'text-info', // default
        'url' => null,
    ]);

    $resource = new NotificationResource($notification);
    $array = $resource->toArray(new Request);

    expect($array['icon'])->toBe('o-bell');
    expect($array['color'])->toBe('text-info');
    expect($array['url'])->toBeNull();
});
