<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

covers(ZabbixService::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->unit = Unit::create(['name' => 'واحد تست']);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('bw');
});

test('it monitoring pages render and check permissions', function () {
    // Mock ZabbixService so pages don't try to hit real API
    $mock = Mockery::mock(ZabbixService::class);
    $mock->shouldReceive('getLatestValues')->andReturn(['item1' => 10.5]);
    $this->app->instance(ZabbixService::class, $mock);

    // Test Wireless page
    Livewire::actingAs($this->user)
        ->test('it.wireless')
        ->assertOk()
        ->assertSee('دستگاه های بی سیم');

    // Test Networks page
    Livewire::actingAs($this->user)
        ->test('it.networks')
        ->assertOk()
        ->assertSee('داشبورد فناوری اطلاعات');

    // Test RBAC: guest
    auth()->logout();
    $this->get('/it/wireless')->assertRedirect('/login');

    // Test RBAC: user without 'bw'
    $noPermUser = User::factory()->create();
    $noPermUser->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
    $this->actingAs($noPermUser)
        ->get('/it/wireless')
        ->assertStatus(403);
});
