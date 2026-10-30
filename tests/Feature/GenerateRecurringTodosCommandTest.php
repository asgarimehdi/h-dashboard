<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateRecurringTodos;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

covers(GenerateRecurringTodos::class);

class GenerateRecurringTodosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock: the occurrence gate compares `$next <= now`, so a
        // drifting wall clock makes "one day back" fixtures flaky.
        Carbon::setTestNow(now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    /**
     * Issue #1278 (Contract A): a rerun the same day is a natural no-op — the
     * next occurrence is in the future, so nothing is created. A one-day-back
     * fixture is deliberately one day, not two: two days back legitimately
     * catch up two occurrences and would steer the test toward a wrong guard.
     */
    public function test_second_run_on_the_same_day_creates_no_second_instance(): void
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

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 0 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 2);

        $instance = Todo::where('recurrence_rule', 'none')->first();
        $template->refresh();
        $this->assertTrue($template->last_generated_at->equalTo($instance->start_at));
    }

    /**
     * Issue #1278: a crash between Todo::create and the last_generated_at
     * update must roll back atomic (no orphan instance, template not
     * advanced), and after the failure reason is gone the retry succeeds.
     */
    public function test_failure_between_create_and_update_rolls_back_and_retries(): void
    {
        $lastGenerated = now()->subDay();
        $template = Todo::create([
            'title' => 'Daily backup',
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => $lastGenerated,
        ]);

        $explode = true;
        Todo::updating(function (Todo $todo) use (&$explode, $template): void {
            if ($todo->id === $template->id && $todo->isDirty('last_generated_at') && $explode) {
                throw new \RuntimeException('boom-last-generated-at');
            }
        });

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('1 template(s) failed — see the log.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('todos', 1);
        $template->refresh();
        // DB-stored datetimes are second-precision; the frozen clock is not.
        $this->assertSame($lastGenerated->format('Y-m-d H:i:s'), $template->last_generated_at->format('Y-m-d H:i:s'));

        $explode = false;
        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 1 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 2);
    }

    /**
     * Issue #1278 (isolation): one bad template must not starve the rest —
     * the failure is reported, the run continues, and the exit code says so.
     */
    public function test_a_failing_template_does_not_starve_the_others(): void
    {
        $unit = Unit::create(['name' => 'Ward I']);
        $failedAt = now()->subDay();
        $good = Todo::create([
            'title' => 'Good template',
            'unit_id' => $unit->id,
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);
        $bad = Todo::create([
            'title' => 'Exploding template',
            'unit_id' => $unit->id,
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);

        Todo::creating(function (Todo $todo): void {
            if ($todo->title === 'Exploding template') {
                throw new \RuntimeException('boom');
            }
        });

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 1 new todo instance(s)')
            ->assertExitCode(1);

        $this->assertDatabaseCount('todos', 3);
        $this->assertEquals('Good template', Todo::where('recurrence_rule', 'none')->first()->title);

        $good->refresh();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $good->last_generated_at->format('Y-m-d H:i:s'));

        // The failed template rolled back and was not advanced — it stays
        // due and retryable instead of half-applied.
        $bad->refresh();
        $this->assertSame($failedAt->format('Y-m-d H:i:s'), $bad->last_generated_at->format('Y-m-d H:i:s'));
    }

    /**
     * Issue #1278 (occurrence gate): a template whose next occurrence has
     * not been reached yet is skipped, not future-dated.
     */
    public function test_template_whose_next_occurrence_is_in_the_future_is_skipped(): void
    {
        $template = Todo::create([
            'title' => 'Daily backup',
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now(),
        ]);

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 0 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 1);
        $template->refresh();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $template->last_generated_at->format('Y-m-d H:i:s'));
    }

    /**
     * Issue #1278: an instance for the same occurrence already exists (a
     * half-applied pre-fix row) — it must not be recreated; the template's
     * last_generated_at is repaired to that occurrence instead.
     */
    public function test_existing_instance_for_the_same_occurrence_is_not_recreated(): void
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

        // Reset via the query builder (not Eloquent) so no model cache/state
        // interferes — this simulates a half-applied pre-fix row.
        DB::table('todos')->where('id', $template->id)->update(['last_generated_at' => now()->subDay()]);

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 0 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 2);

        $instance = Todo::where('recurrence_rule', 'none')->first();
        $template->refresh();
        $this->assertTrue($template->last_generated_at->equalTo($instance->start_at));
    }

    /**
     * Issue #1278: the same title in two different units is two independent
     * templates — the occurrence guard must not swallow one of them.
     */
    public function test_same_title_in_two_units_both_generate(): void
    {
        $unitA = Unit::create(['name' => 'Ward Alpha']);
        $unitB = Unit::create(['name' => 'Ward Beta']);
        Todo::create([
            'title' => 'Shared task',
            'unit_id' => $unitA->id,
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);
        Todo::create([
            'title' => 'Shared task',
            'unit_id' => $unitB->id,
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now()->subDay(),
        ]);

        $this->artisan('todos:generate-recurring')
            ->expectsOutputToContain('Created 2 new todo instance(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 4);

        $instances = Todo::where('recurrence_rule', 'none')->get();
        $this->assertCount(2, $instances);
        $this->assertEqualsCanonicalizing([$unitA->id, $unitB->id], $instances->pluck('unit_id')->all());
    }

    /**
     * Issue #1278: dry-run must count with the same occurrence gate — a
     * not-yet-reached template is reported as skipped, not "would create".
     */
    public function test_dry_run_reports_not_yet_reached_templates_as_skipped(): void
    {
        Todo::create([
            'title' => 'Daily backup',
            'start_at' => now()->subDay(),
            'is_completed' => false,
            'recurrence_rule' => 'daily',
            'recurrence_interval' => 1,
            'last_generated_at' => now(),
        ]);

        $this->artisan('todos:generate-recurring', ['--dry-run' => true])
            ->expectsOutputToContain('Would create 0 instance(s); 1 template(s) not yet reached.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('todos', 1);
    }
}
