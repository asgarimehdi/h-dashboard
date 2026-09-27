<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PersonController;
use App\Models\Person;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(PersonController::class);

class PersonApiTest extends TestCase
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

    public function test_unauthenticated_user_cannot_access_persons(): void
    {
        $response = $this->getJson('/api/persons');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_persons(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet('/api/persons', $token);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_can_show_person(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet("/api/persons/{$person->n_code}", $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['n_code' => $person->n_code]]);
    }

    public function test_user_can_create_person(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $existingPerson = Person::first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPost('/api/persons', [
            'n_code' => '1111111111',
            'f_name' => 'John',
            'l_name' => 'Doe',
            't_id' => $existingPerson->t_id,
            'e_id' => $existingPerson->e_id,
            's_id' => $existingPerson->s_id,
            'r_id' => $existingPerson->r_id,
            'u_id' => $unit->id,
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('persons', ['n_code' => '1111111111']);
    }

    public function test_user_can_update_person(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPut("/api/persons/{$person->n_code}", [
            'f_name' => 'Updated',
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['f_name' => 'Updated']]);
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code, 'f_name' => 'Updated']);
    }

    public function test_user_can_delete_person(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $existingPerson = Person::first();

        // Create a person not linked to any user account to avoid FK constraint, using same ref IDs
        $person = Person::factory()->create([
            'n_code' => '2222222222',
            'u_id' => $unit->id,
            't_id' => $existingPerson->t_id,
            'e_id' => $existingPerson->e_id,
            's_id' => $existingPerson->s_id,
            'r_id' => $existingPerson->r_id,
        ]);

        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);
        $response = $this->apiDelete("/api/persons/{$person->n_code}", $token);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('persons', ['n_code' => $person->n_code]);
    }

    public function test_create_person_requires_required_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPost('/api/persons', [], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id']);
    }

    /**
     * Issue #532: scope check must happen AFTER validation, not before.
     * Sending an invalid u_id should get a 422 (validation), not a 403 (scope).
     */
    public function test_update_person_with_invalid_unit_returns_validation_error_not_scope_error(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        // u_id=999999 doesn't exist in units table → should be 422 (validation)
        // Before the fix, this could return 403 (scope check ran first, leaking info)
        $response = $this->apiPut("/api/persons/{$person->n_code}", [
            'u_id' => 999999,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['u_id']);
    }

    /**
     * Issue #532: updating person to a unit outside accessible scope → 403,
     * but only AFTER validation passes.
     */
    public function test_update_person_to_inaccessible_unit_returns_403(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        // Create a unit that EXISTS but is NOT in the user's accessible scope
        $otherUnit = Unit::create(['name' => 'Inaccessible Unit']);

        $response = $this->apiPut("/api/persons/{$person->n_code}", [
            'u_id' => $otherUnit->id,
        ], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unit not accessible.']);
    }
}
