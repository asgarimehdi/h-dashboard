<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateRecurringTodos;
use App\Models\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

covers(GenerateRecurringTodos::class);

class GenerateRecurringTodosCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_todo_creates_instance_and_updates_template(): void
    {
        $template = Todo::create([
            'title' => 'Daily backup',
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 1 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 2);
        $newOne = Todo::where('recurrence_rule', 'none')->first();
        $this->assertNotNull($newOne);
        $this->assertEquals('Daily backup', $newOne->title);

        $template->refresh();
        $this->assertNotNull($template->last_generated_at);
    }

    public function test_non_recurring_todos_are_ignored(): void
    {
        Todo::create([
            'title' => 'One-off',
            'start_at' => now(),
            'is_completed' => false,
            'recurrence_rule' => 'none',
        ]);

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Found 0 recurring todo(s) due')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 1);
    }

    public function test_dry_run_creates_nothing(): void
    {
        Todo::create([
            'title' => 'Daily backup',
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);

        $this->artisan('todos:generate-recurring', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 1);
    }
}
