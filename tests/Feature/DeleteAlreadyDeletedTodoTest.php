<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TodoController;
use App\Models\Todo;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TodoController::class);

class DeleteAlreadyDeletedTodoTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_delete_already_deleted_todo_returns_404(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);
        $todoId = $todo->id;

        // First delete - should succeed
        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todoId}");
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify it's deleted
        $this->assertDatabaseMissing('todos', ['id' => $todoId]);

        // Second delete - should return 404, not 500
        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/todos/{$todoId}");
        $response->assertStatus(404);
    }
}
