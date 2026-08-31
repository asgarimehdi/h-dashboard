<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Api\TodoController::class)]

class DeleteAlreadyDeletedTodoTest extends TestCase
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

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);
        $this->seed(PermissionSeeder::class);
        $user->givePermissionTo('calendar');

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_delete_already_deleted_todo_returns_404(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

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
