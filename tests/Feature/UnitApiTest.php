<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UnitController;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(UnitController::class);

class UnitApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_unauthenticated_user_cannot_access_units(): void
    {
        $response = $this->getJson('/api/units');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_units(): void
    {
        $this->createUserWithUnit(['organization']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read']);

        $response = $this->apiGet('/api/units', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_unit_list_respects_accessible_scope(): void
    {
        ['unit' => $accessible] = $this->createUserWithUnit(['organization']);
        $accessible->update(['name' => 'Accessible']);
        $inaccessible = Unit::create(['name' => 'Inaccessible']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read']);

        $response = $this->apiGet('/api/units', $token);

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($inaccessible->id, $ids);
    }

    public function test_user_can_show_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['organization']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read']);

        $response = $this->apiGet("/api/units/{$unit->id}", $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name']]);
    }

    public function test_user_cannot_show_inaccessible_unit(): void
    {
        $this->createUserWithUnit(['organization']);
        $inaccessible = Unit::create(['name' => 'Hidden']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read']);

        $response = $this->apiGet("/api/units/{$inaccessible->id}", $token);

        $response->assertStatus(403);
    }

    public function test_user_can_create_unit(): void
    {
        $this->createUserWithUnit(['organization']);
        $type = UnitType::create(['name' => 'Test Type']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read', 'units:write']);

        $response = $this->apiPost('/api/units', [
            'name' => 'New Unit',
            'unit_type_id' => $type->id,
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('units', ['name' => 'New Unit']);
    }

    public function test_user_can_update_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['organization']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read', 'units:write']);

        $response = $this->apiPut("/api/units/{$unit->id}", [
            'name' => 'Updated Unit',
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['name' => 'Updated Unit']]);
    }

    public function test_user_cannot_update_inaccessible_unit(): void
    {
        $this->createUserWithUnit(['organization']);
        $inaccessible = Unit::create(['name' => 'Hidden']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read', 'units:write']);

        $response = $this->apiPut("/api/units/{$inaccessible->id}", [
            'name' => 'Hacked',
        ], $token);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_accessible_unit(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['organization']);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read', 'units:write']);

        $response = $this->apiDelete("/api/units/{$unit->id}", $token);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_user_cannot_delete_unit_with_children(): void
    {
        ['unit' => $parent] = $this->createUserWithUnit(['organization']);
        $child = Unit::create(['name' => 'Child', 'parent_id' => $parent->id]);
        $user = User::first();
        $token = $this->createApiToken($user, ['units:read', 'units:write']);

        $response = $this->apiDelete("/api/units/{$parent->id}", $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete unit with children.']);
    }

    public function test_pagination_per_page_is_limited(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['organization']);

        // Create additional units within the same scope
        for ($i = 0; $i < 150; $i++) {
            Unit::create([
                'name' => "Unit {$i}",
                'parent_id' => $unit->id,
            ]);
        }

        $user = User::first();
        $token = $this->createApiToken($user, ['units:read']);
        $response = $this->apiGet('/api/units?per_page=1000', $token);

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }
}
