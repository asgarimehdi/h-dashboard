<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDailyReports;
use App\Models\DailyReport;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(GenerateDailyReports::class);

/**
 * Issue #1021 — the command used to spend five round-trips per unit
 * (Unit::find + one count per relation + exists + create), i.e. ~4,200
 * sequential queries for the documented 834-unit organisation, every morning.
 * The units and the two counts are one pass over the whole set now; only the
 * deliberate check-then-act write pair stays per unit.
 *
 * The plan's "global scopes still apply" case is a no-op in this codebase and is
 * asserted as such below rather than silently skipped: neither Ticket nor Todo
 * uses SoftDeletes, and HasOrganizationalScope contributes only the opt-in
 * `accessible()` local scope — nothing is applied to `Unit::tickets()` /
 * `Unit::todos()` that a `DB::table` aggregate would bypass.
 *
 * Maintenance warning: that equivalence is what makes the raw aggregate correct.
 * If a soft-delete or a global scope is ever added to `Ticket` or `Todo`, the
 * aggregates must move to the Eloquent builder — and no test in this file can
 * catch it, because a trashed row cannot be created before the trait exists.
 */
class GenerateDailyReportsCommandTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(fn ($query) => $this->statements[] = $query->sql);
    }

    /**
     * Run $callback and return only the statements it issued.
     *
     * @return array<int, string>
     */
    private function queriesDuring(\Closure $callback): array
    {
        $this->statements = [];
        $callback();

        return $this->statements;
    }

    /**
     * How many full-row SELECTs $statements ran against $table.
     *
     * @param  array<int, string>  $statements
     */
    private function selectsOn(array $statements, string $table): int
    {
        return count(array_filter(
            $statements,
            fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, '"'.$table.'"'),
        ));
    }

    private function openTicket(Unit $unit): Ticket
    {
        return Ticket::create([
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'subject' => 't',
            'content' => 'c',
            'status' => 'created',
            'unit_id' => $unit->id,
        ]);
    }

    private function openTodo(Unit $unit, string $title = 'todo'): Todo
    {
        return Todo::create([
            'title' => $title,
            'start_at' => now(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);
    }

    public function test_report_generated_for_unit_filter(): void
    {
        $unit = Unit::create(['name' => 'Ward A']);
        Ticket::create([
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'subject' => 't',
            'content' => 'c',
            'status' => 'created',
            'unit_id' => $unit->id,
        ]);
        Todo::create([
            'title' => 'todo',
            'start_at' => now(),
            'is_completed' => false,
            'unit_id' => $unit->id,
        ]);

        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])
            ->expectsOutputToContain('Generated 1 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
        $report = DailyReport::first();
        $this->assertEquals($unit->id, $report->unit_id);
        $this->assertStringContainsString('open tickets', $report->summary);
    }

    public function test_report_not_duplicated_same_day(): void
    {
        $unit = Unit::create(['name' => 'Ward B']);
        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])->assertExitCode(0);
        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])
            ->expectsOutputToContain('Generated 0 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
    }

    public function test_dry_run_creates_no_report(): void
    {
        $unit = Unit::create(['name' => 'Ward C']);

        $this->artisan('reports:generate-daily', ['--unit' => $unit->id, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 0);
    }

    /**
     * Issue #836: the scheduler runs this command with no authenticated user
     * and no `--unit`, so an empty scope must mean "every unit", not "none".
     */
    public function test_report_generated_for_all_units_without_authenticated_user(): void
    {
        Session::flush();
        $first = Unit::create(['name' => 'Ward X']);
        $second = Unit::create(['name' => 'Ward Y']);

        $this->assertNull(auth()->user(), 'Precondition: no authenticated user.');

        $this->artisan('reports:generate-daily')
            ->expectsOutputToContain('Generated 2 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            DailyReport::pluck('unit_id')->all()
        );
    }

    public function test_unit_filter_still_scopes_to_one_unit(): void
    {
        $target = Unit::create(['name' => 'Ward Target']);
        Unit::create(['name' => 'Ward Other']);

        $this->artisan('reports:generate-daily', ['--unit' => $target->id])
            ->expectsOutputToContain('Generated 1 daily report(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 1);
        $this->assertEquals($target->id, DailyReport::first()->unit_id);
    }

    // ==================== issue #1021: batched counts ====================

    /**
     * Each unit gets its own numbers, and the summary stays byte-identical —
     * `Unit {name}: {n} open tickets, {m} open todos.` is stored data.
     *
     * Two of the three units have an empty side on purpose: `beta` has no tickets
     * and `gamma` has no todos, so this also pins the zero the `?? 0` default has
     * to produce (issue #1021 Step 3).
     */
    public function test_each_unit_gets_its_own_counts_and_summary(): void
    {
        $alpha = Unit::create(['name' => 'Alpha']);
        $beta = Unit::create(['name' => 'Beta']);
        $gamma = Unit::create(['name' => 'Gamma']);

        $this->openTicket($alpha);
        $this->openTicket($alpha);
        $this->openTodo($alpha, 'a1');
        $this->openTodo($alpha, 'a2');
        $this->openTodo($alpha, 'a3');

        $this->openTodo($beta, 'b1');

        $this->openTicket($gamma);

        $this->artisan('reports:generate-daily')
            ->expectsOutputToContain('Generated 3 daily report(s)')
            ->assertExitCode(0);

        $reports = DailyReport::query()->get()->keyBy('unit_id');

        $this->assertSame(3, $reports->count());
        $this->assertSame(
            ['open_tickets' => 2, 'open_todos' => 3],
            $reports[$alpha->id]->payload,
        );
        $this->assertSame(
            ['open_tickets' => 0, 'open_todos' => 1],
            $reports[$beta->id]->payload,
        );
        $this->assertSame(
            ['open_tickets' => 1, 'open_todos' => 0],
            $reports[$gamma->id]->payload,
        );

        $this->assertSame('Unit Alpha: 2 open tickets, 3 open todos.', $reports[$alpha->id]->summary);
        $this->assertSame('Unit Beta: 0 open tickets, 1 open todos.', $reports[$beta->id]->summary);
        $this->assertSame('Unit Gamma: 1 open tickets, 0 open todos.', $reports[$gamma->id]->summary);
    }

    /**
     * The two aggregate predicates are the load-bearing part of the change: a
     * dropped `status != 'completed'` / `is_completed = false` would silently
     * rewrite every stored daily report from here on.
     */
    public function test_completed_rows_are_excluded_from_both_counts(): void
    {
        $unit = Unit::create(['name' => 'Ward Counted']);

        $this->openTicket($unit);
        Ticket::create([
            'ticket_code' => 'T-'.strtoupper(Str::random(8)),
            'subject' => 'done', 'content' => 'c', 'status' => 'completed',
            'unit_id' => $unit->id,
        ]);
        $this->openTodo($unit, 'open');
        Todo::create([
            'title' => 'done', 'start_at' => now(),
            'is_completed' => true, 'unit_id' => $unit->id,
        ]);

        $this->artisan('reports:generate-daily', ['--unit' => $unit->id])->assertExitCode(0);

        $this->assertSame(
            ['open_tickets' => 1, 'open_todos' => 1],
            DailyReport::first()->payload,
            'completed tickets/todos must not be counted',
        );
    }

    /**
     * A group-by aggregate is only correct if each unit's counts come from its
     * own rows — the failure mode is an org-wide number copied onto every unit.
     */
    public function test_a_unit_only_counts_its_own_rows(): void
    {
        $busy = Unit::create(['name' => 'Ward Busy']);
        $quiet = Unit::create(['name' => 'Ward Quiet']);

        for ($i = 0; $i < 4; $i++) {
            $this->openTicket($busy);
            $this->openTodo($busy, 'b'.$i);
        }

        $this->artisan('reports:generate-daily')->assertExitCode(0);

        $reports = DailyReport::query()->get()->keyBy('unit_id');

        $this->assertSame(['open_tickets' => 4, 'open_todos' => 4], $reports[$busy->id]->payload);
        $this->assertSame(['open_tickets' => 0, 'open_todos' => 0], $reports[$quiet->id]->payload);
    }

    /**
     * The regression this issue fixes: the read side used to cost 3N queries
     * (one Unit::find and two counts per unit), so the fan-out grew with the
     * organisation. It is constant now — one pass over `units`, `tickets` and
     * `todos` each, for any N.
     *
     * The residual per-unit queries are the deliberate `exists()` + `create()`
     * pair; the plan leaves that check-then-act alone, so this test asserts the
     * read side (which is what the issue is about) instead of a constant total.
     */
    public function test_read_queries_do_not_grow_with_the_unit_count(): void
    {
        $seed = function (int $from, int $to): void {
            for ($i = $from; $i <= $to; $i++) {
                $unit = Unit::create(['name' => 'Ward '.$i]);
                $this->openTicket($unit);
                $this->openTodo($unit, 'todo '.$i);
            }
        };

        $seed(1, 2);
        $twoUnits = $this->queriesDuring(fn () => Artisan::call('reports:generate-daily'));

        $seed(3, 6);
        $sixUnits = $this->queriesDuring(fn () => Artisan::call('reports:generate-daily'));

        // `units` reads twice on purpose and always twice: once for
        // AccessService::allUnitIds() (the scope) and once for the preload. The
        // counts are one GROUP BY each. All constant — the old shape was
        // 2N + 1 units reads, N tickets reads and N todos reads (7/3/3 for the
        // six-unit run).
        $expected = ['units' => 2, 'tickets' => 1, 'todos' => 1];

        foreach ($expected as $table => $count) {
            $this->assertSame($count, $this->selectsOn($twoUnits, $table), "{$table} reads must not fan out (2 units)");
            $this->assertSame($count, $this->selectsOn($sixUnits, $table), "{$table} reads must not fan out (6 units)");
        }
    }

    /**
     * The headline number from the issue: ~5 round-trips per unit becomes a
     * constant read phase plus the write pair. With 10 units the old shape issued
     * 1 + 5N = 51 queries; the new one issues 4 + 2N = 24.
     *
     * This counts for itself instead of using `InteractsWithTestSetup::
     * assertQueryCount()`: that helper's listener is an arrow function, so
     * `$queries[] = …` writes to the closure's by-value copy and the assertion
     * ends up comparing against an always-empty array — it cannot fail, on this
     * test or on UnitTreeServiceTest's. Flagged as a follow-up, not fixed here
     * (out of this issue's scope, and the helper is shared).
     */
    public function test_query_count_is_far_below_the_old_five_per_unit(): void
    {
        $units = 10;

        for ($i = 1; $i <= $units; $i++) {
            $unit = Unit::create(['name' => 'Ward '.$i]);
            $this->openTicket($unit);
            $this->openTodo($unit, 'todo '.$i);
        }

        $statements = $this->queriesDuring(fn () => Artisan::call('reports:generate-daily'));

        // 4 + 2N: allUnitIds + units preload + two aggregates + exists/create per unit.
        $this->assertLessThanOrEqual(
            4 + 2 * $units,
            count($statements),
            'the batched shape must hold: '.count($statements).' queries',
        );
        $this->assertLessThan(
            5 * $units,
            count($statements),
            'must stay below the old five-queries-per-unit fan-out',
        );

        $this->assertDatabaseCount('daily_reports', $units);
    }

    /**
     * Fail-closed: with nothing in scope the command must exit 0 having written
     * nothing, never fall back to "unrestricted" (the scope rules in AGENTS.md).
     */
    public function test_no_units_in_scope_writes_nothing_and_exits_zero(): void
    {
        $this->assertDatabaseCount('units', 0);

        $this->artisan('reports:generate-daily')
            ->expectsOutputToContain('No units found')
            ->assertExitCode(0);

        $this->assertDatabaseCount('daily_reports', 0);
    }
}
