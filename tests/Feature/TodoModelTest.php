<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Todo::class);

class TodoModelTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createTodoWithRelations(array $todoAttrs = []): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $todo = Todo::factory()->create(array_merge([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
        ], $todoAttrs));

        return ['todo' => $todo, 'user' => $user, 'unit' => $unit];
    }

    // --- Relationships ---

    public function test_todo_belongs_to_unit(): void
    {
        ['todo' => $todo, 'unit' => $unit] = $this->createTodoWithRelations();

        $this->assertNotNull($todo->unit);
        $this->assertEquals($unit->id, $todo->unit->id);
    }

    public function test_todo_belongs_to_user(): void
    {
        ['todo' => $todo, 'user' => $user] = $this->createTodoWithRelations();

        $this->assertNotNull($todo->user);
        $this->assertEquals($user->id, $todo->user->id);
    }

    public function test_todo_has_many_tickets(): void
    {
        ['todo' => $todo, 'user' => $user, 'unit' => $unit] = $this->createTodoWithRelations();

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت تست',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
            'task_id' => $todo->id,
        ]);

        $this->assertCount(1, $todo->tickets);
    }

    // --- Fillable ---

    public function test_todo_allows_mass_assignment(): void
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $todo = Todo::factory()->create([
            'title' => 'وظیفه تست',
            'is_completed' => true,
            'unit_id' => $unit->id,
        ]);

        $this->assertEquals('وظیفه تست', $todo->title);
        $this->assertTrue($todo->is_completed);
    }

    // --- Boolean cast ---

    public function test_todo_is_completed_is_not_boolean_by_default(): void
    {
        $todo = Todo::factory()->create(['is_completed' => false]);

        $this->assertFalse((bool) $todo->is_completed);
    }

    public function test_todo_completed_state(): void
    {
        $todo = Todo::factory()->completed()->create();

        $this->assertTrue((bool) $todo->is_completed);
    }

    public function test_todo_pending_state(): void
    {
        $todo = Todo::factory()->pending()->create();

        $this->assertFalse((bool) $todo->is_completed);
    }

    // --- Timestamps ---

    public function test_todo_has_timestamps(): void
    {
        $todo = Todo::factory()->create();

        $this->assertNotNull($todo->created_at);
        $this->assertNotNull($todo->updated_at);
    }

    // --- start_at and end_at ---

    public function test_todo_has_start_and_end_dates(): void
    {
        $todo = Todo::factory()->create([
            'start_at' => now()->subDays(5),
            'end_at' => now()->addDays(5),
        ]);

        $this->assertNotNull($todo->start_at);
        $this->assertNotNull($todo->end_at);
    }

    public function test_todo_can_have_null_end_date(): void
    {
        $todo = Todo::factory()->create([
            'end_at' => null,
        ]);

        $this->assertNull($todo->end_at);
    }
}
