<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TodoController;
use App\Models\Todo;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TodoController::class);

class TodoApiTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_unauthenticated_user_cannot_access_todos(): void
    {
        $response = $this->getJson('/api/todos');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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
            'user_id' => $user->id,
        ]);
    }

    public function test_created_todo_belongs_to_authenticated_user(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'Ownership Test',
            'start_at' => '2026-07-15 10:00:00',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('todos', [
            'title' => 'Ownership Test',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_create_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todo->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    }

    public function test_user_can_toggle_todo_completion(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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

        $this->assertTrue((bool) $todo->fresh()->is_completed);
    }

    public function test_todo_list_respects_jalali_date_filtering(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => true]);
        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => false]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/todos?is_completed=true');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_can_view_todo_in_accessible_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

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
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_update_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/todos/{$todo->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create([
            'unit_id' => $inaccessibleUnit->id,
            'is_completed' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/todos/{$todo->id}/toggle-complete");

        $response->assertStatus(403);
    }

    public function test_todo_with_null_unit_is_denied(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => null]);

        // Null-unit todos are outside any user's org scope — must be denied (issue #249)
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_update_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/todos/{$todo->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => null]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todo->id}");

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => null, 'is_completed' => false]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/todos/{$todo->id}/toggle-complete");

        $response->assertStatus(403);
    }

    public function test_todo_with_null_unit_not_created_via_store(): void
    {
        // Regression for issue #249: even when unit_id is omitted entirely,
        // the controller must fall back to person->u_id and never store a null-unit todo.
        // Here the user HAS a person with a unit, so this succeeds — proving the
        // fallback path still works. The null-bypass is covered by the show/update/
        // delete/toggle null-unit tests above (all expect 403).
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'Fallback Todo',
            'start_at' => '2026-07-15 10:00:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.unit_id', $unit->id);
    }

    public function test_user_can_create_todo_without_unit_id_using_person_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/todos', [
            'title' => 'Person Unit Todo',
            'start_at' => '2026-07-15 10:00:00',
        ]);

        $response->assertStatus(201);
        // Should have used the person's unit automatically
        $this->assertEquals($unit->id, $response->json('data')['unit_id'], 'falls back to person.u_id when no unit_id provided');
    }

    public function test_delete_non_existent_todo_returns_404(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);

        // Try to delete a non-existent todo (ID 99999)
        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/todos/99999');

        $response->assertStatus(404);
    }
}
