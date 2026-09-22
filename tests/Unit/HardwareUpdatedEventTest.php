<?php

namespace Tests\Unit;

use App\Events\HardwareUpdated;
use App\Models\Hardware;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

covers(HardwareUpdated::class);

uses(TestCase::class);

test('HardwareUpdated stores hardware and action', function () {
    $hardware = Hardware::factory()->make();
    $event = new HardwareUpdated($hardware, 'created');

    expect($event->hardware)->toBeInstanceOf(Hardware::class);
    expect($event->action)->toBe('created');
});

test('HardwareUpdated supports all action types', function () {
    $hardware = Hardware::factory()->make();

    $actions = ['created', 'updated', 'deleted', 'bulk_mark', 'bulk_deleted'];

    foreach ($actions as $action) {
        $event = new HardwareUpdated($hardware, $action);
        expect($event->action)->toBe($action);
    }
});

test('HardwareUpdated dispatches successfully', function () {
    $dispatched = false;

    Event::listen(HardwareUpdated::class, function () use (&$dispatched) {
        $dispatched = true;
    });

    $hardware = Hardware::factory()->make();
    event(new HardwareUpdated($hardware, 'created'));

    expect($dispatched)->toBeTrue();
});
