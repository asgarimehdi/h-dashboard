<?php

use App\Http\Middleware\SafeRoleOrPermission;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

covers(SafeRoleOrPermission::class);

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('SafeRoleOrPermission allows guests to pass through', function () {
    // Guest request to a route wrapped in safe_role_or_permission
    // Should not hit Spatie's check and return 200 (or proceed to next)
    $this->get('/test-safe-route')
        ->assertStatus(200);
});

test('SafeRoleOrPermission blocks authenticated users without permission', function () {
    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['u_id' => $unit->id]);
    $user = User::factory()->create(['n_code' => $person->n_code]);

    $this->actingAs($user);

    // User exists but lacks the specific permission
    $this->get('/test-safe-route')
        ->assertStatus(403);
});

test('SafeRoleOrPermission allows authenticated users with permission', function () {
    $unit = Unit::factory()->create();
    $person = Person::factory()->create(['u_id' => $unit->id]);
    $user = User::factory()->create(['n_code' => $person->n_code]);
    $user->givePermissionTo('test-permission');

    $this->actingAs($user);

    $this->get('/test-safe-route')
        ->assertStatus(200);
});
