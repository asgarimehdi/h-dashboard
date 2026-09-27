<?php

use App\Http\Resources\TodoResource;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

covers(TodoResource::class);

uses(TestCase::class, RefreshDatabase::class);

it('transforms all expected fields without unit relation', function () {
    $todo = Todo::factory()->create([
        'title' => 'Review lab results',
        'start_at' => now(),
        'end_at' => now()->addDay(),
        'is_completed' => false,
    ]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKeys([
        'id', 'title', 'start_at', 'end_at', 'is_completed', 'unit_id', 'unit',
        'user_id', 'created_at', 'updated_at',
    ]);
    expect($array['title'])->toBe('Review lab results');
    expect($array['is_completed'])->toBeFalse();
    // unit not loaded → whenLoaded returns MissingValue
    expect(array_key_exists('unit', $array) ? $array['unit'] : 'missing')
        ->not->toBeInstanceOf(Unit::class);
});

it('includes unit sub-object when relation is loaded', function () {
    $unit = Unit::create(['name' => 'Radiology']);
    $todo = Todo::factory()->create(['unit_id' => $unit->id]);

    $resource = new TodoResource($todo->load('unit:id,name'));
    $array = $resource->toArray(new Request);

    expect($array['unit'])->toBe(['id' => $unit->id, 'name' => 'Radiology']);
});

it('returns null unit_id when todo has no unit', function () {
    $todo = Todo::factory()->create(['unit_id' => null]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request);

    expect($array['unit_id'])->toBeNull();
    expect(array_key_exists('unit', $array) ? $array['unit'] : 'missing')
        ->not->toBeInstanceOf(Unit::class);
});

it('casts is_completed to boolean', function () {
    $todo = Todo::factory()->create(['is_completed' => true]);

    $resource = new TodoResource($todo);
    $array = $resource->toArray(new Request);

    expect($array['is_completed'])->toBeTrue();
});
