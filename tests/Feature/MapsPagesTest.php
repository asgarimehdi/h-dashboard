<?php

use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Unit::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->user, 'unit' => $this->unit] = $this->createUserWithUnit(['map']);
});

test('guest is redirected from maps pages', function () {
    foreach (['/maps/route', '/maps/route2', '/maps/county', '/maps/unit', '/maps/interactive', '/maps/point'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

test('authenticated user with map permission can load maps pages', function () {
    $this->actingAs($this->user);

    foreach (['maps/route', 'maps/route2', 'maps/unit', 'maps/interactive', 'maps/point'] as $component) {
        Livewire::test($component)->assertStatus(200);
    }
});

test('maps county page renders map container', function () {
    $this->actingAs($this->user);
    Livewire::test('maps.county')->assertStatus(200);
});
