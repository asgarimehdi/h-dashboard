<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class TodoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        // Create required reference data
        $tId = \DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = \DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = \DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = \DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) rand(1000000000, 9999999999);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_todos(): void
    {
        $response = $this->getJson('/api/todos');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        Todo::factory()->count(3)->create(['unit_id' => $unit->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/todos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'start_at', 'end_at', 'is_completed', 'unit_id'],
                ],
            ]);
    }

    public function test_user_can_create_todo_in_accessible_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'تست تسک جدید',
            'start_at' => '2026-07-15 10:00:00',
            'end_at' => '2026-07-20 10:00:00',
            'unit_id' => $unit->id,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('todos', [
            'title' => 'تست تسک جدید',
            'unit_id' => $unit->id,
        ]);
    }

    public function test_user_cannot_create_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit();
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'Unauthorized Todo',
            'start_at' => '2026-07-15 10:00:00',
            'unit_id' => $inaccessibleUnit->id,
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized to create todo in this unit.']);
    }

    public function test_user_can_update_own_todo(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'Old Title',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/todos/{$todo->id}", [
            'title' => 'Updated Title',
            'start_at' => '2026-07-16 10:00:00',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'title' => 'Updated Title',
                ],
            ]);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_user_can_delete_own_todo(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todo->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    }

    public function test_user_can_toggle_todo_completion(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);

        $this->assertFalse($todo->is_completed);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/todos/{$todo->id}/toggle-complete");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_completed' => true,
                ],
            ]);

        $this->assertTrue($todo->fresh()->is_completed);
    }

    public function test_todo_list_respects_jalali_date_filtering(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-20 10:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/todos?date=2026-07-15');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_todo_list_filters_by_is_completed(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => true]);
        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => false]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/todos?is_completed=true');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_can_view_todo_in_accessible_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/todos/{$todo->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $todo->id,
                ],
            ]);
    }

    public function test_user_cannot_view_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit();
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_update_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit();
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/todos/{$todo->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit();
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit();
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create([
            'unit_id' => $inaccessibleUnit->id,
            'is_completed' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/todos/{$todo->id}/toggle-complete");

        $response->assertStatus(403);
    }

    public function test_todo_with_null_unit_is_accessible(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create(['unit_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/todos/{$todo->id}");

        $response->assertStatus(200);
    }

    public function test_user_can_create_todo_without_unit_id_using_person_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $person = Person::factory()->create(['n_code' => $user->n_code]);
        $person->update(['u_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'No Unit Todo',
            'start_at' => '2026-07-15 10:00:00',
        ]);

        $response->assertStatus(201);
    }

    public function test_delete_non_existent_todo_returns_404(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        // Try to delete a non-existent todo (ID 99999)
        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/todos/99999');

        $response->assertStatus(404);
    }
}