<?php

namespace Tests\Feature;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1282 — the advanced report used to run one `count()` plus four
 * cloned counts per ticket render. This file pins the numbers before the
 * conditional aggregate and the versioned cache replace them, and keeps
 * them pinned afterwards.
 *
 * The expected numbers are written by hand from the fixture matrix, never
 * derived from the component itself, so a refactor that changes a bucket
 * fails here instead of agreeing with its own output.
 */
#[CoversNothing]
class ReportsAdvancedAggregateParityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Testable $component;

    private Unit $root;

    private Unit $child;

    private Unit $grandchild;

    private Unit $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        // A frozen clock keeps "today", "yesterday" and the Jalali default
        // range stable even when the suite crosses midnight.
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Session::flush();

        $this->buildFixture();
    }

    /**
     * The matrix from the issue, created before the first reportData() call
     * so no `saveQuietly()` back-date can land behind a warm cache.
     */
    private function buildFixture(): void
    {
        $this->root = Unit::factory()->create(['name' => 'root-unit']);
        $this->child = Unit::factory()->create(['name' => 'child-unit', 'parent_id' => $this->root->id]);
        $this->grandchild = Unit::factory()->create(['name' => 'grandchild-unit', 'parent_id' => $this->child->id]);
        $this->foreign = Unit::factory()->create(['name' => 'foreign-unit']);

        $person = Person::factory()->create(['u_id' => $this->root->id]);
        $user = User::factory()->create(['n_code' => $person->n_code]);
        $user->givePermissionTo('manage_personnel');
        $user->units()->attach($this->root->id, ['role' => 'staff', 'is_primary' => true]);
        $this->actingAs($user);
        Session::put('current_unit_id', $this->root->id);

        $this->makeTicket('T1', $this->root, 'urgent', 'created', now()->subDay(), now());
        $this->makeTicket('T2', $this->child, 'normal', 'forwarded', now()->addDay(), now()->subDays(10));
        $this->makeTicket('T3', $this->grandchild, 'low', 'completed', null, now()->subDays(40));
        $this->makeTicket('T4', $this->root, 'urgent', 'accepted', now()->subDay(), now());
        $this->makeTicket('T5', $this->foreign, 'urgent', 'created', now()->subDay(), now());
        // `medium` is storable (the DB CHECK allows it, #954) but no bucket
        // counts it; it must appear in `total` only.
        $this->makeTicket('T6', $this->root, 'medium', 'created', null, now());

        Todo::factory()->create([
            'title' => 'Todo1',
            'unit_id' => $this->root->id,
            'is_completed' => false,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDay(),
        ]);
        Todo::factory()->create([
            'title' => 'Todo2',
            'unit_id' => $this->child->id,
            'is_completed' => true,
            'start_at' => now()->subDays(2),
            'end_at' => null,
        ]);
        Todo::factory()->create([
            'title' => 'Todo3',
            'unit_id' => $this->foreign->id,
            'is_completed' => false,
            'end_at' => now()->subDay(),
        ]);

        // Second person inside the scope; the user's own Person is the first.
        Person::factory()->create(['u_id' => $this->grandchild->id]);
        Person::factory()->create(['u_id' => $this->foreign->id]);

        $this->component = Livewire::test('reports.advanced');
    }

    private function makeTicket(
        string $code,
        Unit $unit,
        string $priority,
        string $status,
        ?Carbon $deadline,
        Carbon $createdAt,
    ): Ticket {
        $ticket = Ticket::factory()->create([
            'ticket_code' => $code,
            'unit_id' => $unit->id,
            'priority' => $priority,
            'status' => $status,
            'deadline' => $deadline,
        ]);

        // `created_at` is not fillable; back-date it without firing an event.
        $ticket->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $ticket;
    }

    /**
     * Run reportData() with an explicit filter state.
     *
     * @param  array<string, mixed>  $state
     * @return array{total: int, byDay: array, byUnit: array, details: array}
     */
    private function reportDataWith(string $type, array $state = []): array
    {
        $component = $this->component->instance();
        $component->reportType = $type;

        // Every call starts from the mount defaults, so a filter set by an
        // earlier call cannot leak into the next one.
        $component->dateFrom = $this->jalali(now()->subDays(30));
        $component->dateTo = $this->jalali(now());
        $component->unitId = null;
        $component->parentUnitId = null;
        $component->rootUnitId = null;
        $component->statusFilter = 'all';

        foreach ($state as $key => $value) {
            $component->{$key} = $value;
        }

        $data = $component->reportData();

        // `GROUP BY` does not promise an order; compare unit buckets as a map.
        ksort($data['byUnit']);

        return $data;
    }

    private function jalali(Carbon $date): string
    {
        return Jalalian::fromCarbon($date)->format('Y/m/d');
    }

    /**
     * @return array<int, string>
     */
    private function captureSql(\Closure $callback): array
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $callback();

        return $sql;
    }

    /**
     * @param  array<int, string>  $sql
     * @return array<int, string>
     */
    private function tableSql(array $sql, string $table): array
    {
        return array_values(array_filter(
            $sql,
            fn (string $statement): bool => preg_match('/\bfrom\s+"?'.$table.'"?/i', $statement) === 1,
        ));
    }

    // ------------------------------------------------------------------
    // Characterization: numbers pinned by hand for every filter shape.
    // ------------------------------------------------------------------

    public function test_tickets_matrix_matches_hand_counted_numbers(): void
    {
        $data = $this->reportDataWith('tickets');

        $this->assertSame(4, $data['total'], 'T1, T2, T4 and T6 fall inside the default 30-day range');
        $this->assertSame(2, $data['details']['urgent'], 'T1 and T4');
        $this->assertSame(1, $data['details']['normal'], 'T2');
        $this->assertSame(0, $data['details']['low'], 'T3 is 40 days old and outside the range');
        $this->assertSame(1, $data['details']['overdue'], 'only T1: T4 is accepted, T6 has no deadline');

        $this->assertSame(['child-unit' => 1, 'root-unit' => 3], $data['byUnit']);

        $byDay = collect($data['byDay'])->pluck('count', 'day')->toArray();
        $this->assertSame(3, $byDay[$this->jalali(now())], 'T1, T4 and T6 were created today');
        $this->assertSame(1, $byDay[$this->jalali(now()->subDays(10))], 'T2');
        $this->assertSame(4, array_sum($byDay), 'byDay must agree with total on a ≤365-day range');
    }

    public function test_tickets_unit_filter_uses_the_selected_subtree(): void
    {
        // The unit filter is hierarchical, so selecting root also selects its
        // child subtree: T1, T2, T4 and T6.
        $root = $this->reportDataWith('tickets', ['unitId' => $this->root->id]);
        $this->assertSame(4, $root['total'], 'T1, T2, T4 and T6 belong to the root subtree');
        $this->assertSame(2, $root['details']['urgent']);
        $this->assertSame(1, $root['details']['overdue']);
        $this->assertSame(['child-unit' => 1, 'root-unit' => 3], $root['byUnit']);

        $child = $this->reportDataWith('tickets', ['unitId' => $this->child->id]);
        $this->assertSame(1, $child['total'], 'T2 belongs to the child subtree');
        $this->assertSame(1, $child['details']['normal']);
        $this->assertSame(0, $child['details']['urgent']);

        $outside = $this->reportDataWith('tickets', ['unitId' => $this->foreign->id]);
        $this->assertSame(0, $outside['total'], 'a unit outside the scope must leak nothing');
        $this->assertSame([], $outside['byUnit']);
        $this->assertSame(0, array_sum(array_column($outside['byDay'], 'count')));
    }

    public function test_tickets_status_filter_matches_only_that_status(): void
    {
        $accepted = $this->reportDataWith('tickets', ['statusFilter' => 'accepted']);

        $this->assertSame(1, $accepted['total'], 'T4 only');
        $this->assertSame(1, $accepted['details']['urgent']);
        $this->assertSame(0, $accepted['details']['overdue'], 'T4 is accepted, not overdue');
    }

    public function test_a_wider_range_includes_the_older_ticket(): void
    {
        $data = $this->reportDataWith('tickets', [
            'dateFrom' => $this->jalali(now()->subDays(60)),
            'dateTo' => $this->jalali(now()),
        ]);

        $this->assertSame(5, $data['total'], 'T1..T4 and T6');
        $this->assertSame(1, $data['details']['low'], 'T3 at 40 days');
        $this->assertSame(1, $data['details']['overdue']);
        $this->assertSame(5, array_sum(array_column($data['byDay'], 'count')));
    }

    public function test_a_single_day_range_contains_only_todays_rows(): void
    {
        $today = $this->jalali(now());
        $data = $this->reportDataWith('tickets', ['dateFrom' => $today, 'dateTo' => $today]);

        $this->assertSame(3, $data['total'], 'T1, T4 and T6 were created today');
        $this->assertSame(2, $data['details']['urgent']);
        $this->assertSame(1, $data['details']['overdue']);
        $this->assertSame(3, array_sum(array_column($data['byDay'], 'count')));
    }

    public function test_todos_matrix_and_filters_match_hand_counted_numbers(): void
    {
        $all = $this->reportDataWith('todos');
        $this->assertSame(2, $all['total'], 'Todo1 and Todo2; Todo3 is out of scope');
        $this->assertSame(['child-unit' => 1, 'root-unit' => 1], $all['byUnit']);
        $this->assertSame([], $all['details'], 'todos have no four-bucket detail block');

        $this->assertSame(1, $this->reportDataWith('todos', ['statusFilter' => 'completed'])['total']);
        $this->assertSame(1, $this->reportDataWith('todos', ['statusFilter' => 'overdue'])['total']);
    }

    public function test_persons_branch_keeps_its_scope_only_details(): void
    {
        // Two people share the scope: the user's own Person plus one in the
        // grandchild unit. The foreign person must not leak.
        $all = $this->reportDataWith('persons');
        $this->assertSame(2, $all['total']);
        $this->assertSame(2, array_sum($all['details']['byEstekhdam']));
        $this->assertSame(['grandchild-unit' => 1, 'root-unit' => 1], $all['byUnit']);

        // Pre-existing inconsistency, pinned on purpose: the three lookup
        // payloads ignore the date and status filters while `total` honours
        // them. Issue #1282 does not "fix" this.
        $past = $this->reportDataWith('persons', [
            'dateFrom' => $this->jalali(now()->subDays(10)),
            'dateTo' => $this->jalali(now()->subDays(5)),
        ]);
        $this->assertSame(0, $past['total'], 'nobody was created in that past window');
        $this->assertSame(2, array_sum($past['details']['byEstekhdam']), 'details ignore the date filter');

        $inactive = $this->reportDataWith('persons', ['statusFilter' => 'inactive']);
        $this->assertSame(0, $inactive['total'], 'everyone is active');
        $this->assertSame(2, array_sum($inactive['details']['byEstekhdam']), 'details ignore the status filter');
    }

    public function test_persons_report_query_budget_is_calibrated_to_seven_report_queries(): void
    {
        // Step-1 measurement on the pre-change checkout: 7 report queries
        // (total, byDay, byUnit, eager units, three details lookups). Force
        // a cold entry without resetting the `unit_hierarchy` version, as
        // the tickets budget test does.
        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $measured = [];
        DB::listen(function ($query) use (&$measured): void {
            $measured[] = $query->sql;
        });

        $data = $this->reportDataWith('persons');

        $reportSql = array_merge(
            $this->tableSql($measured, 'persons'),
            $this->tableSql($measured, 'units'),
            $this->tableSql($measured, 'estekhdams'),
            $this->tableSql($measured, 'tahsils'),
            $this->tableSql($measured, 'semats'),
        );

        $this->assertSame(7, count($reportSql), 'persons report ran '.count($reportSql)." queries:\n".implode("\n---\n", $reportSql));
        $this->assertSame(2, $data['total']);
    }

    public function test_repeated_persons_report_reads_hit_the_cache_and_run_no_report_queries(): void
    {
        $this->reportDataWith('persons');

        $sql = $this->captureSql(fn () => $this->reportDataWith('persons'));
        $reportSql = array_merge(
            $this->tableSql($sql, 'persons'),
            $this->tableSql($sql, 'units'),
            $this->tableSql($sql, 'estekhdams'),
            $this->tableSql($sql, 'tahsils'),
            $this->tableSql($sql, 'semats'),
        );

        $this->assertSame([], $reportSql, 'a cache hit must not run a report query');
    }

    public function test_persons_cache_keys_distinguish_ranges_units_and_statuses(): void
    {
        // Two 11-day windows with the same day count but different totals.
        // `days` is never the sole distinguisher: from/to carry the range.
        $recent = $this->reportDataWith('persons', [
            'dateFrom' => $this->jalali(now()->subDays(10)),
            'dateTo' => $this->jalali(now()),
        ]);
        $older = $this->reportDataWith('persons', [
            'dateFrom' => $this->jalali(now()->subDays(30)),
            'dateTo' => $this->jalali(now()->subDays(20)),
        ]);
        $this->assertSame(2, $recent['total']);
        $this->assertSame(0, $older['total'], 'same day count, different range — must not reuse the entry');
        $this->assertSame(2, array_sum($older['details']['byEstekhdam']), 'details ignore the date filter, per entry');

        $root = $this->reportDataWith('persons', ['unitId' => $this->root->id]);
        $child = $this->reportDataWith('persons', ['unitId' => $this->child->id]);
        $this->assertSame(2, $root['total'], 'the root subtree includes the grandchild');
        $this->assertSame(1, $child['total'], 'same range, different unit');

        $inactive = $this->reportDataWith('persons', ['statusFilter' => 'inactive']);
        $this->assertSame(0, $inactive['total'], 'everyone is active — same range, different status');
        $this->assertSame(2, array_sum($inactive['details']['byEstekhdam']), 'details ignore the status filter, per entry');
    }

    public function test_person_writes_invalidate_the_cached_persons_report(): void
    {
        $this->assertSame(2, $this->reportDataWith('persons')['total']);

        $this->assertCacheInvalidated('report_persons', fn () => Person::factory()->create([
            'u_id' => $this->root->id,
        ]));
        $afterCreate = $this->reportDataWith('persons');
        $this->assertSame(3, $afterCreate['total'], 'a created person is visible on the next read');
        $this->assertSame(3, array_sum($afterCreate['details']['byEstekhdam']));

        $created = Person::query()->latest('id')->firstOrFail();
        $created->update(['u_id' => $this->foreign->id]);

        $afterMove = $this->reportDataWith('persons');
        $this->assertSame(2, $afterMove['total'], 'a person moved out of scope leaves the total');

        Person::query()->where('u_id', $this->grandchild->id)->firstOrFail()->delete();

        $this->assertSame(1, $this->reportDataWith('persons')['total'], 'a deleted person leaves the total');
    }

    public function test_estekhdam_rename_invalidates_the_cached_persons_report(): void
    {
        $type = Estekhdam::create(['name' => 'rename-me-estekhdam']);
        Person::factory()->create(['u_id' => $this->root->id, 'e_id' => $type->id]);

        $before = $this->reportDataWith('persons');
        $this->assertArrayHasKey('rename-me-estekhdam', $before['details']['byEstekhdam']);

        $this->assertCacheInvalidated('report_persons', fn () => $type->update(['name' => 'rename-me-estekhdam-renamed']));

        $after = $this->reportDataWith('persons');
        $this->assertArrayNotHasKey('rename-me-estekhdam', $after['details']['byEstekhdam'], 'the old label must not survive');
        $this->assertArrayHasKey('rename-me-estekhdam-renamed', $after['details']['byEstekhdam']);
    }

    public function test_tahsil_rename_invalidates_the_cached_persons_report(): void
    {
        $type = Tahsil::create(['name' => 'rename-me-tahsil']);
        Person::factory()->create(['u_id' => $this->root->id, 't_id' => $type->id]);

        $before = $this->reportDataWith('persons');
        $this->assertArrayHasKey('rename-me-tahsil', $before['details']['byTahsil']);

        $this->assertCacheInvalidated('report_persons', fn () => $type->update(['name' => 'rename-me-tahsil-renamed']));

        $after = $this->reportDataWith('persons');
        $this->assertArrayNotHasKey('rename-me-tahsil', $after['details']['byTahsil'], 'the old label must not survive');
        $this->assertArrayHasKey('rename-me-tahsil-renamed', $after['details']['byTahsil']);
    }

    public function test_semat_rename_invalidates_the_cached_persons_report(): void
    {
        $type = Semat::create(['name' => 'rename-me-semat']);
        Person::factory()->create(['u_id' => $this->root->id, 's_id' => $type->id]);

        $before = $this->reportDataWith('persons');
        $this->assertArrayHasKey('rename-me-semat', $before['details']['bySemat']);

        $this->assertCacheInvalidated('report_persons', fn () => $type->update(['name' => 'rename-me-semat-renamed']));

        $after = $this->reportDataWith('persons');
        $this->assertArrayNotHasKey('rename-me-semat', $after['details']['bySemat'], 'the old label must not survive');
        $this->assertArrayHasKey('rename-me-semat-renamed', $after['details']['bySemat']);
    }

    // ------------------------------------------------------------------
    // One conditional aggregate instead of five counts.
    // ------------------------------------------------------------------

    public function test_ticket_report_runs_one_conditional_aggregate_and_no_cloned_counts(): void
    {
        // The mount render warms the default key; force the miss so the SQL is
        // observable.
        app(CacheInvalidationServiceInterface::class)->increment('report_tickets');

        $sql = $this->captureSql(fn () => $this->reportDataWith('tickets'));
        $ticketSql = $this->tableSql($sql, 'tickets');

        $aggregates = array_values(array_filter(
            $ticketSql,
            fn (string $statement): bool => preg_match('/count\(\*\)\s+filter\s*\(\s*where/i', $statement) === 1,
        ));

        $this->assertCount(1, $aggregates, 'total + four buckets must be a single aggregate');

        foreach (['total', 'urgent', 'normal', 'low', 'overdue'] as $alias) {
            $this->assertStringContainsString('AS '.$alias, $aggregates[0]);
        }

        $this->assertSame([], array_values(array_filter(
            $ticketSql,
            fn (string $statement): bool => preg_match('/count\(\*\)\s+as\s+"?aggregate"?/i', $statement) === 1,
        )), 'the cloned count() queries are gone');

        $this->assertLessThanOrEqual(3, count($ticketSql), 'aggregate + daily series + byUnit');
    }

    public function test_ticket_report_query_budget_is_calibrated_to_the_single_aggregate(): void
    {
        // Force a cold report entry without resetting the `unit_hierarchy`
        // version: that would invalidate AccessService's request memo and add
        // a recursive-CTE query to the measurement.
        app(CacheInvalidationServiceInterface::class)->increment('report_tickets');

        $measured = [];
        DB::listen(function ($query) use (&$measured): void {
            $measured[] = $query->sql;
        });

        $data = $this->reportDataWith('tickets');

        $reportSql = array_merge(
            $this->tableSql($measured, 'tickets'),
            $this->tableSql($measured, 'units'),
        );

        // Calibrated on the pre-change checkout: 8 report queries (total, byDay,
        // byUnit, eager units, four cloned counts). After the aggregate it is 4.
        $this->assertSame(4, count($reportSql), 'ticket report ran '.count($reportSql)." queries:\n".implode("\n---\n", $reportSql));
        $this->assertSame(4, $data['total']);
    }

    // ------------------------------------------------------------------
    // Cache: hit, key completeness, invalidation.
    // ------------------------------------------------------------------

    public function test_repeated_ticket_report_reads_hit_the_cache_and_run_no_report_queries(): void
    {
        $this->reportDataWith('tickets');

        $sql = $this->captureSql(fn () => $this->reportDataWith('tickets'));
        $reportSql = array_merge(
            $this->tableSql($sql, 'tickets'),
            $this->tableSql($sql, 'units'),
        );

        $this->assertSame([], $reportSql, 'a cache hit must not run a report query');
    }

    public function test_repeated_todo_report_reads_hit_the_cache_and_run_no_report_queries(): void
    {
        $this->reportDataWith('todos');

        $sql = $this->captureSql(fn () => $this->reportDataWith('todos'));
        $reportSql = array_merge(
            $this->tableSql($sql, 'todos'),
            $this->tableSql($sql, 'units'),
        );

        $this->assertSame([], $reportSql, 'a cache hit must not run a report query');
    }

    public function test_equal_length_ranges_units_and_statuses_build_distinct_cache_keys(): void
    {
        // Two 11-day windows with the same day count but different totals.
        $recent = $this->reportDataWith('tickets', [
            'dateFrom' => $this->jalali(now()->subDays(10)),
            'dateTo' => $this->jalali(now()),
        ]);
        $older = $this->reportDataWith('tickets', [
            'dateFrom' => $this->jalali(now()->subDays(30)),
            'dateTo' => $this->jalali(now()->subDays(20)),
        ]);
        $this->assertSame(4, $recent['total']);
        $this->assertSame(0, $older['total'], 'same day count, different range — must not reuse the entry');

        $root = $this->reportDataWith('tickets', ['unitId' => $this->root->id]);
        $child = $this->reportDataWith('tickets', ['unitId' => $this->child->id]);
        $this->assertSame(4, $root['total'], 'the root subtree includes the child');
        $this->assertSame(1, $child['total'], 'same range, different unit');

        $created = $this->reportDataWith('tickets', ['statusFilter' => 'created']);
        $accepted = $this->reportDataWith('tickets', ['statusFilter' => 'accepted']);
        $this->assertSame(2, $created['total'], 'T1 and T6');
        $this->assertSame(1, $accepted['total'], 'T4 — same range, different status');
    }

    public function test_ticket_writes_invalidate_the_cached_report(): void
    {
        $this->assertSame(4, $this->reportDataWith('tickets')['total']);

        $this->assertCacheInvalidated('report_tickets', fn () => Ticket::factory()->create([
            'unit_id' => $this->root->id,
            'priority' => 'low',
            'status' => 'created',
        ]));
        $afterCreate = $this->reportDataWith('tickets');
        $this->assertSame(5, $afterCreate['total'], 'a created ticket is visible on the next read');
        $this->assertSame(1, $afterCreate['details']['low']);

        $ticket = Ticket::query()->where('ticket_code', 'T2')->firstOrFail();
        $ticket->update(['priority' => 'urgent']);

        $afterUpdate = $this->reportDataWith('tickets');
        $this->assertSame(3, $afterUpdate['details']['urgent'], 'normal → urgent moves the bucket');
        $this->assertSame(0, $afterUpdate['details']['normal']);

        $ticket->delete();

        $afterDelete = $this->reportDataWith('tickets');
        $this->assertSame(4, $afterDelete['total'], 'a deleted ticket leaves the total');
        $this->assertSame(2, $afterDelete['details']['urgent']);
    }

    public function test_todo_writes_invalidate_the_cached_report(): void
    {
        $this->assertSame(2, $this->reportDataWith('todos')['total']);

        $this->assertCacheInvalidated('report_todos', fn () => Todo::factory()->create([
            'unit_id' => $this->root->id,
            'is_completed' => false,
            'end_at' => null,
        ]));

        $afterCreate = $this->reportDataWith('todos');
        $this->assertSame(3, $afterCreate['total']);
        $this->assertSame(2, $afterCreate['byUnit']['root-unit']);

        Todo::query()->where('title', 'Todo2')->firstOrFail()->delete();
        $this->assertSame(2, $this->reportDataWith('todos')['total'], 'a deleted todo leaves the total');
    }

    public function test_renaming_a_unit_refreshes_the_cached_unit_labels(): void
    {
        $before = $this->reportDataWith('tickets');
        $this->assertArrayHasKey('root-unit', $before['byUnit']);

        $this->root->update(['name' => 'root-unit-renamed']);

        $after = $this->reportDataWith('tickets');
        $this->assertArrayNotHasKey('root-unit', $after['byUnit'], 'the old label must not survive');
        $this->assertSame(3, $after['byUnit']['root-unit-renamed']);
    }
}
