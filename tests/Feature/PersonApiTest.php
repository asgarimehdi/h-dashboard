<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class PersonApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::create(['name' => 'Test Unit']);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);
        $this->seed(PermissionSeeder::class);
        $user->givePermissionTo('manage_personnel');

        return ['user' => $user, 'unit' => $unit, 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId];
    }

    public function test_unauthenticated_user_cannot_access_persons(): void
    {
        $response = $this->getJson('/api/persons');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_persons(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/persons');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_user_can_show_person(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/persons/{$person->n_code}");

        $response->assertStatus(200)
            ->assertJson(['data' => ['n_code' => $person->n_code]]);
    }

    public function test_user_can_create_person(): void
    {
        ['user' => $user, 'unit' => $unit, 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/persons', [
            'n_code' => '1111111111',
            'f_name' => 'John',
            'l_name' => 'Doe',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unit->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('persons', ['n_code' => '1111111111']);
    }

    public function test_user_can_update_person(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::first();

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/persons/{$person->n_code}", [
            'f_name' => 'Updated',
        ]);

        $response->assertStatus(200)
            ->assertJson(['data' => ['f_name' => 'Updated']]);
        $this->assertDatabaseHas('persons', ['n_code' => $person->n_code, 'f_name' => 'Updated']);
    }

    public function test_user_can_delete_person(): void
    {
        ['user' => $user, 'unit' => $unit, 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId] = $this->createUserWithUnit();
        // Create a person not linked to any user account to avoid FK constraint, using same ref IDs
        $person = Person::create([
            'n_code' => '2222222222',
            'f_name' => 'Delete',
            'l_name' => 'Me',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unit->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/persons/{$person->n_code}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('persons', ['n_code' => $person->n_code]);
    }

    public function test_create_person_requires_required_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/persons', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['n_code', 'f_name', 'l_name', 't_id', 'e_id', 's_id', 'r_id', 'u_id']);
    }
}
