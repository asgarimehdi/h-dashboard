<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Tahsil;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Database\Factories\TahsilFactory;
use Database\Factories\UnitTypeFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1821 — the three standalone report pages (persons / todos / units)
 * used to re-run their full aggregate payload on every Livewire render.
 * `chartPayload()` now caches only the aggregate slice for 10 minutes
 * (`report_persons` / `report_todos` / `report_units`), keyed by scope hash,
 * filter extras and the `unit_hierarchy` generation, while the paginator and
 * its rows stay live.
 *
 * These tests pin the contract: a warm render runs no aggregate SQL, a filter
 * or scope change busts the key, and a write in an invalidated namespace
 * refreshes the numbers.
 */
#[CoversNothing]
class ReportsPayloadCacheTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $root;

    private Unit $child;

    private Unit $foreign;

    private UnitType $rootType;

    private UnitType $childType;

    private User $userA;

    private User $userB;

    private Tahsil $secondTahsil;

    protected function setUp(): void
    {
        parent::setUp();
        // A frozen clock keeps the Jalali default range and "today" stable.
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Session::flush();

        $this->buildFixture();
    }

    /**
     * All fixtures are created before the first render in every test: a warm
     * cache is exactly what these tests must be able to prove.
     */
    private function buildFixture(): void
    {
        $this->root = Unit::factory()->create(['name' => 'root-unit']);
        $this->child = Unit::factory()->create(['name' => 'child-unit', 'parent_id' => $this->root->id]);
        $this->foreign = Unit::factory()->create(['name' => 'foreign-unit']);

        $this->rootType = UnitTypeFactory::new()->create(['name' => 'type-root']);
        $this->childType = UnitTypeFactory::new()->create(['name' => 'type-child']);
        $this->root->update(['unit_type_id' => $this->rootType->id]);
        $this->child->update(['unit_type_id' => $this->childType->id]);
        $this->foreign->update(['unit_type_id' => $this->rootType->id]);

        $this->secondTahsil = TahsilFactory::new()->create(['name' => 'second-tahsil']);

        // Scope A = root + child. Three persons live in it.
        $personA = Person::factory()->create(['u_id' => $this->root->id]);
        $this->userA = User::factory()->create(['n_code' => $personA->n_code]);
        $this->userA->givePermissionTo('manage_personnel');
        $this->userA->units()->attach($this->root->id, ['role' => 'staff', 'is_primary' => true]);

        Person::factory()->create(['u_id' => $this->root->id, 't_id' => $this->secondTahsil->id]);
        Person::factory()->create(['u_id' => $this->child->id]);

        // Scope B = foreign. Two persons live in it.
        $personB = Person::factory()->create(['u_id' => $this->foreign->id]);
        $this->userB = User::factory()->create(['n_code' => $personB->n_code]);
        $this->userB->givePermissionTo('manage_personnel');
        $this->userB->units()->attach($this->foreign->id, ['role' => 'staff', 'is_primary' => true]);
        Person::factory()->create(['u_id' => $this->foreign->id]);

        $this->actingAs($this->userA);
        Session::put('current_unit_id', $this->root->id);

        // Scope A todos: 1 completed, 3 pending (one overdue).
        $this->makeTodo('todo-pending', $this->root->id, now()->subDay(), now()->addDay(), false);
        $this->makeTodo('todo-completed', $this->child->id, now()->subDays(2), now()->subDay(), true);
        $this->makeTodo('todo-open-ended', $this->root->id, now()->subDays(10), null, false);
        $this->makeTodo('todo-overdue', $this->root->id, now()->subDays(5), now()->subDays(3), false);
        $this->makeTodo('todo-foreign', $this->foreign->id, now()->subDay(), now()->addDay(), false);
    }

    private function makeTodo(string $title, int $unitId, Carbon $startAt, ?Carbon $endAt, bool $completed): Todo
    {
        return Todo::factory()->create([
            'title' => $title,
            'unit_id' => $unitId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_completed' => $completed,
        ]);
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

    /**
     * @param  array<int, string>  $sql
     * @return array<int, string>
     */
    private function groupBySql(array $sql): array
    {
        return array_values(array_filter(
            $sql,
            fn (string $statement): bool => preg_match('/\bgroup by\b|\bgenerate_series\b/i', $statement) === 1,
        ));
    }

    /**
     * @param  array<int, string>  $sql
     */
    private function assertNoAggregateSql(array $sql, string $table): void
    {
        $this->assertSame([], $this->groupBySql($sql), 'a cache hit must not run the aggregate queries');
        $this->assertCount(
            2,
            $this->tableSql($sql, $table),
            "a warm render must issue only the pagination count and page select on {$table}:\n".implode("\n---\n", $this->tableSql($sql, $table)),
        );
    }

    // ------------------------------------------------------------------
    // persons
    // ------------------------------------------------------------------

    public function test_persons_second_render_with_same_filters_runs_no_aggregate_sql(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $cold = $this->captureSql(fn () => Livewire::test('reports.persons'));
        $this->assertNotSame([], $this->groupBySql($cold), 'a cold render must run the aggregates');

        $warm = $this->captureSql(fn () => Livewire::test('reports.persons'));
        $this->assertNoAggregateSql($warm, 'persons');
    }

    public function test_persons_filter_change_runs_aggregates_and_changes_numbers(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $component = Livewire::test('reports.persons');
        $this->assertSame(3, $component->instance()->chartPayload()['total'], 'root + child hold three persons');

        $sql = $this->captureSql(fn () => $component->set('selectedTahsilId', (string) $this->secondTahsil->id));
        $this->assertNotSame([], $this->groupBySql($sql), 'a filter change must run the aggregates again');

        $chart = $component->instance()->chartPayload();
        $expected = Person::query()
            ->whereIn('u_id', [$this->root->id, $this->child->id])
            ->where('t_id', $this->secondTahsil->id)
            ->count();

        $this->assertSame(1, $expected);
        $this->assertSame($expected, $chart['total']);
    }

    public function test_persons_unit_rename_invalidates_cached_unit_labels(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $warm = Livewire::test('reports.persons');
        $this->assertSame(2, $warm->instance()->chartPayload()['byUnit']['root-unit']);

        // A Unit write bumps `report_units` / `unit_hierarchy`, never
        // `report_persons`; the hierarchy extra in the key is what saves us.
        $this->root->update(['name' => 'renamed-root']);

        $test = null;
        $sql = $this->captureSql(function () use (&$test): void {
            $test = Livewire::test('reports.persons');
        });
        $this->assertNotSame([], $this->groupBySql($sql), 'a unit rename must run the aggregates again');

        $chart = $test->instance()->chartPayload();
        $this->assertSame(2, $chart['byUnit']['renamed-root'] ?? null, 'byUnit must show the new unit name');
        $this->assertArrayNotHasKey('root-unit', $chart['byUnit']);
    }

    // ------------------------------------------------------------------
    // todos
    // ------------------------------------------------------------------

    public function test_todos_second_render_with_same_filters_runs_no_aggregate_sql(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_todos');

        $cold = $this->captureSql(fn () => Livewire::test('reports.todos'));
        $this->assertNotSame([], $this->groupBySql($cold), 'a cold render must run the aggregates');

        $warm = $this->captureSql(fn () => Livewire::test('reports.todos'));
        $this->assertNoAggregateSql($warm, 'todos');
    }

    public function test_todos_date_range_change_runs_aggregates_and_changes_by_day(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_todos');

        $component = Livewire::test('reports.todos');
        $this->assertSame(1, $component->instance()->chartPayload()['completed']);

        $sql = $this->captureSql(fn () => $component->set('dateFrom', $this->jalali(now()->subDay())));
        $this->assertNotSame([], $this->groupBySql($sql), 'a date change must run the aggregates again');

        $chart = $component->instance()->chartPayload();
        $this->assertSame(1, $chart['completed'], 'the completed todo ended after the new `from`, so overlap keeps it');
        $this->assertSame(2, $chart['pending'], 'the pending todo and the open-ended one stay in range');
        $this->assertSame(1, array_sum(array_column($chart['byDay'], 'count')));
        $this->assertSame($this->jalali(now()->subDay()), $chart['byDay'][0]['day']);
    }

    public function test_todos_write_invalidates_cached_counts(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_todos');

        $warm = Livewire::test('reports.todos');
        $this->assertSame(3, $warm->instance()->chartPayload()['pending']);

        $this->makeTodo('todo-new', $this->root->id, now()->subDay(), now()->addDay(), false);

        $test = null;
        $sql = $this->captureSql(function () use (&$test): void {
            $test = Livewire::test('reports.todos');
        });
        $this->assertNotSame([], $this->groupBySql($sql), 'a Todo write must run the aggregates again');
        $this->assertSame(4, $test->instance()->chartPayload()['pending']);
    }

    // ------------------------------------------------------------------
    // units
    // ------------------------------------------------------------------

    public function test_units_second_render_with_same_filters_runs_no_aggregate_sql(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_units');

        $cold = $this->captureSql(fn () => Livewire::test('reports.units'));
        $this->assertNotSame([], $this->groupBySql($cold), 'a cold render must run the aggregates');

        $warm = $this->captureSql(fn () => Livewire::test('reports.units'));
        $this->assertNoAggregateSql($warm, 'units');
    }

    public function test_units_boundary_filter_change_runs_aggregates(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_units');

        $component = Livewire::test('reports.units');
        $this->assertSame(2, $component->instance()->chartPayload()['total']);

        $sql = $this->captureSql(fn () => $component->set('showOnlyNoBoundary', '0'));
        $this->assertNotSame([], $this->groupBySql($sql), 'a boundary filter change must run the aggregates again');

        $chart = $component->instance()->chartPayload();
        // No unit in the fixture has a boundary, so "only with boundary" is empty.
        $this->assertSame(0, $chart['total']);
        // no_boundary / with_boundary ignore the boundary filter, by design.
        $this->assertSame(2, $chart['no_boundary']);
        $this->assertSame(0, $chart['with_boundary']);
    }

    public function test_units_unit_type_rename_invalidates_cached_by_type(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_units');

        $warm = Livewire::test('reports.units');
        $this->assertSame(1, $warm->instance()->chartPayload()['byType']['type-child']);

        $this->childType->update(['name' => 'renamed-type']);

        $test = null;
        $sql = $this->captureSql(function () use (&$test): void {
            $test = Livewire::test('reports.units');
        });
        $this->assertNotSame([], $this->groupBySql($sql), 'a UnitType write must run the aggregates again');

        $chart = $test->instance()->chartPayload();
        $this->assertSame(1, $chart['byType']['renamed-type'] ?? null);
        $this->assertArrayNotHasKey('type-child', $chart['byType']);
    }

    // ------------------------------------------------------------------
    // scope isolation and paging
    // ------------------------------------------------------------------

    public function test_a_second_scope_never_reads_another_scopes_cached_payload(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $chartA = Livewire::test('reports.persons')->instance()->chartPayload();
        $this->assertSame(3, $chartA['total'], 'scope A = root + child');

        $this->actingAs($this->userB);
        Session::put('current_unit_id', $this->foreign->id);

        $chartB = Livewire::test('reports.persons')->instance()->chartPayload();
        $this->assertSame(2, $chartB['total'], 'scope B = foreign only');
        $this->assertSame(['foreign-unit' => 2], $chartB['byUnit'], 'scope A entries must not leak into scope B');
    }

    public function test_paging_reuses_cached_aggregates_but_returns_the_next_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            Person::factory()->create(['u_id' => $this->root->id]);
        }

        app(CacheInvalidationServiceInterface::class)->increment('report_persons');

        $component = Livewire::test('reports.persons');
        $firstPage = $component->instance()->chartPayload();
        $this->assertSame(33, $firstPage['total'], 'three fixture persons plus thirty new ones');
        $this->assertCount(25, $firstPage['persons']);

        $sql = $this->captureSql(fn () => $component->call('gotoPage', 2));
        $this->assertSame([], $this->groupBySql($sql), 'paging must reuse the cached aggregates');

        $secondPage = $component->instance()->chartPayload();
        $this->assertSame(2, $secondPage['personsPaginator']->currentPage());
        $this->assertCount(8, $secondPage['persons'], 'the paginator stays live while the aggregates are cached');
    }
}
