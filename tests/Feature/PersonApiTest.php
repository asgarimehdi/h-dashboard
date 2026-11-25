<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PersonController;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
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

    /**
     * Issue #959: hardwares.n_code has no FK, so deleting the person would
     * silently orphan every hardware row. Refuse the delete while any exist.
     */
    public function test_delete_person_with_hardware_is_refused(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $existingPerson = Person::first();

        $person = Person::factory()->create([
            'n_code' => '3333333333',
            'u_id' => $unit->id,
            't_id' => $existingPerson->t_id,
            'e_id' => $existingPerson->e_id,
            's_id' => $existingPerson->s_id,
            'r_id' => $existingPerson->r_id,
        ]);
        $this->createHardware(['n_code' => $person->n_code]);

        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);
        $response = $this->apiDelete("/api/persons/{$person->n_code}", $token);

        $response->assertStatus(422);
        // Neither the person nor the hardware row is touched.
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code]);
        $this->assertDatabaseHas('hardwares', ['n_code' => $person->n_code]);
        $this->assertNotNull(Hardware::where('n_code', $person->n_code)->first()->person);
    }

    public function test_delete_person_with_user_account_is_refused(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $existingPerson = Person::first();

        $person = Person::factory()->create([
            'n_code' => '4444444444',
            'u_id' => $unit->id,
            't_id' => $existingPerson->t_id,
            'e_id' => $existingPerson->e_id,
            's_id' => $existingPerson->s_id,
            'r_id' => $existingPerson->r_id,
        ]);
        $account = User::factory()->create(['n_code' => $person->n_code]);

        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);
        $response = $this->apiDelete("/api/persons/{$person->n_code}", $token);

        // Issue #1720: users.n_code is a RESTRICT FK — refuse with a reason, never 500.
        $response->assertStatus(422);
        $this->assertStringContainsString('user account', (string) $response->json('message'));
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code]);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'n_code' => $person->n_code]);
    }

    /**
     * Issue #1720: "deleting" a user only soft-deletes it, so the users row
     * survives and still trips the RESTRICT FK. The pre-check must see it.
     */
    public function test_delete_person_with_deactivated_user_account_is_refused(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $existingPerson = Person::first();

        $person = Person::factory()->create([
            'n_code' => '5555555555',
            'u_id' => $unit->id,
            't_id' => $existingPerson->t_id,
            'e_id' => $existingPerson->e_id,
            's_id' => $existingPerson->s_id,
            'r_id' => $existingPerson->r_id,
        ]);
        $account = User::factory()->create(['n_code' => $person->n_code]);
        $account->delete();
        $this->assertSoftDeleted('users', ['id' => $account->id]);

        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);
        $response = $this->apiDelete("/api/persons/{$person->n_code}", $token);

        // Same reason as the active-account case, not the generic race-catch message.
        $response->assertStatus(422);
        $this->assertStringContainsString('user account', (string) $response->json('message'));
        $this->assertStringContainsString('deactivated accounts included', (string) $response->json('message'));
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code]);
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
     * Issue #959: n_code is immutable on the update path. hardwares.n_code
     * has no FK, so a rename would silently orphan every hardware row of
     * that person (invisible to every unit, uneditable by anyone).
     */
    public function test_update_person_cannot_change_n_code(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $this->createHardware(['n_code' => $person->n_code]);
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPut("/api/persons/{$person->n_code}", [
            'n_code' => '9999999999',
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code']);

        // The stored n_code is untouched and the hardware row still resolves
        // its person — the exact orphaning #959 forbids.
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code]);
        $this->assertNotNull(Hardware::where('n_code', $person->n_code)->first()->person);
    }

    /**
     * Issue #959: a full-payload PUT that echoes the unchanged n_code (the
     * normal Flutter request shape) must keep working — only a real change
     * is refused.
     */
    public function test_update_person_with_unchanged_n_code_still_succeeds(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPut("/api/persons/{$person->n_code}", [
            'n_code' => $person->n_code,
            'f_name' => 'Updated',
        ], $token);

        $response->assertStatus(200)
            ->assertJson(['data' => ['f_name' => 'Updated']]);
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code, 'f_name' => 'Updated']);
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

    /**
     * Issue #1745: the API validated eight fields and never validated `phone`,
     * so a submitted phone was silently dropped behind a 201 that claimed
     * success. The shared `PersonRules` closes the gap.
     */
    public function test_create_person_stores_the_phone(): void
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
            'phone' => '09123456789',
        ], $token);

        $response->assertStatus(201)->assertJsonPath('data.phone', '09123456789');
        $this->assertDatabaseHas('persons', ['n_code' => '1111111111', 'phone' => '09123456789']);
    }

    public function test_update_person_stores_the_phone(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $response = $this->apiPut("/api/persons/{$person->n_code}", ['phone' => '09123456789'], $token);

        $response->assertStatus(200)->assertJsonPath('data.phone', '09123456789');
        $this->assertDatabaseHas('persons', ['id' => $person->id, 'phone' => '09123456789']);
    }

    public function test_create_person_rejects_an_over_long_phone(): void
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
            'phone' => str_repeat('9', 21),
        ], $token);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->assertDatabaseMissing('persons', ['n_code' => '1111111111']);
    }

    public function test_update_person_rejects_an_over_long_phone(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $person = Person::where('u_id', $unit->id)->first();
        $token = $this->createApiToken($user, ['persons:read', 'persons:write']);

        $this->apiPut("/api/persons/{$person->n_code}", ['phone' => str_repeat('9', 21)], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertNull($person->fresh()->phone);
    }

    public function test_list_rejects_non_integer_unit_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $this->apiGet('/api/persons?unit_id=abc', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_list_rejects_non_integer_semat_id(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $this->apiGet('/api/persons?semat_id=abc', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('semat_id');
    }

    public function test_list_rejects_array_search(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $this->apiGet('/api/persons?search[]=x', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');
    }

    public function test_list_rejects_array_sort_dir(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $this->apiGet('/api/persons?sort_dir[]=x', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_dir');
    }

    public function test_list_filters_by_unit_id(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet("/api/persons?unit_id={$unit->id}", $token);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $person) {
            $this->assertSame($unit->id, (int) $person['u_id']);
        }
    }
}
