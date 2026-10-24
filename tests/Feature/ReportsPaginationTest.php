<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1024 — reports/persons, reports/todos and reports/units built their row
 * list with a bare `->get()`, so an account scoped to a whole ministry hydrated
 * and shipped every row in scope, again on every `wire:model.live` filter
 * change.
 *
 * Two things are locked here: the row list is capped to one page, and the
 * aggregates keep describing the **whole** filtered set (paginating them would
 * silently change what the report means). The fail-closed empty-scope contract
 * lives in ReportsEmptyScopeTest and is deliberately untouched.
 */
class ReportsPaginationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    private function actingWithUnit(User $user): void
    {
        $unit = $user->units()->first();
        $this->actingAs($user);
        session(['current_unit_id' => $unit->id]);
    }

    private function createPersonInUnit(Unit $unit, string $fName = 'شخص', string $lName = 'تست'): Person
    {
        return Person::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'f_name' => $fName, 'l_name' => $lName,
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
    }

    /**
     * A todo that lands inside the report's default 30-day window, so it is in
     * scope regardless of when the suite runs.
     */
    private function createTodoInUnit(Unit $unit, string $title): Todo
    {
        return Todo::factory()->create([
            'title' => $title,
            'unit_id' => $unit->id,
            'start_at' => now()->subDays(5),
            'end_at' => now()->addDays(5),
            'is_completed' => false,
        ]);
    }

    /**
     * Count the `<tr>` rows of the report's own table.
     *
     * The layout renders its own unit table above the report content, so the
     * report's table is the last one on the page — the count starts from there
     * rather than from the first `<tbody>` in the document.
     */
    private function renderedRowCount(string $html): int
    {
        $tableStart = strrpos($html, '<table');
        $this->assertNotFalse($tableStart, 'no table was rendered');

        $tbodyStart = strpos($html, '<tbody>', $tableStart);
        $tbodyEnd = $tbodyStart === false ? false : strpos($html, '</tbody>', $tbodyStart);
        $this->assertNotFalse($tbodyStart, 'the report table has no tbody');
        $this->assertNotFalse($tbodyEnd, 'the report table has no closing tbody');

        return substr_count(substr($html, $tbodyStart, $tbodyEnd - $tbodyStart), '<tr');
    }

    /**
     * Every statement that selects a whole row from `$table`.
     *
     * @param  array<int, string>  $statements
     * @return array<int, string>
     */
    private function rowSelects(array $statements, string $table): array
    {
        return array_values(array_filter(
            $statements,
            fn (string $sql) => str_contains($sql, sprintf('select * from "%s"', $table)),
        ));
    }

    // ==================== persons ====================

    public function test_persons_roster_is_capped_to_one_page(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        // createUserWithUnit already put one person in the unit.
        for ($i = 0; $i < 30; $i++) {
            $this->createPersonInUnit($unit);
        }
        $expectedTotal = Person::where('u_id', $unit->id)->count();
        $this->assertGreaterThan(10, $expectedTotal, 'the fixture must span more than one page');

        $component = Livewire::test('reports.persons')
            ->set('perPage', 10)
            ->assertStatus(200);

        $this->assertSame(10, $this->renderedRowCount($component->html()));
        $component->assertSeeHtml('aria-label="Pagination Navigation"');

        $payload = $component->instance()->chartPayload();
        $this->assertCount(10, $payload['persons'], 'the payload carries only the current page');
        $this->assertSame($expectedTotal, $payload['personsPaginator']->total(), 'the paginator still knows the whole set');
    }

    public function test_persons_aggregates_still_cover_the_whole_set(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 30; $i++) {
            $this->createPersonInUnit($unit);
        }
        $expectedTotal = Person::where('u_id', $unit->id)->count();

        $payload = Livewire::test('reports.persons')
            ->set('perPage', 10)
            ->assertStatus(200)
            ->instance()
            ->chartPayload();

        $this->assertSame($expectedTotal, $payload['total'], 'the total must describe the whole set, not the page');
        $this->assertSame(
            $expectedTotal,
            array_sum($payload['byUnit']),
            'the by-unit breakdown must describe the whole set, not the page',
        );
        $this->assertSame(
            $expectedTotal,
            array_sum($payload['byTahsil']),
            'the by-tahsil breakdown must describe the whole set, not the page',
        );
    }

    public function test_persons_second_page_returns_the_next_rows(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 30; $i++) {
            $this->createPersonInUnit($unit);
        }

        $component = Livewire::test('reports.persons')
            ->set('perPage', 10)
            ->assertStatus(200);

        $firstPage = array_column($component->instance()->chartPayload()['persons'], 'id');

        $component->call('gotoPage', 2, 'page')->assertStatus(200);
        $secondPage = array_column($component->instance()->chartPayload()['persons'], 'id');

        $this->assertCount(10, $firstPage);
        $this->assertCount(10, $secondPage);
        $this->assertSame([], array_intersect($firstPage, $secondPage), 'page 2 must not repeat page 1');
    }

    public function test_persons_filter_change_returns_to_page_one(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 30; $i++) {
            $this->createPersonInUnit($unit);
        }

        // A descendant unit: in scope (the CTE includes it), but small enough
        // that filtering down to it must land on page 1.
        $child = Unit::create(['name' => 'واحد فرزند', 'parent_id' => $unit->id]);
        for ($i = 0; $i < 3; $i++) {
            $this->createPersonInUnit($child);
        }

        $component = Livewire::test('reports.persons')
            ->set('perPage', 10)
            ->assertStatus(200);

        $component->call('gotoPage', 2, 'page')->assertStatus(200);
        $this->assertSame(2, $component->instance()->paginators['page']);

        $component->set('selectedUnitId', $child->id)->assertStatus(200);

        $this->assertSame(1, $component->instance()->paginators['page'], 'a filter change must go back to page 1');
        $this->assertCount(3, $component->instance()->chartPayload()['persons']);
        $this->assertSame(3, $component->instance()->chartPayload()['total']);
    }

    public function test_persons_roster_query_is_bounded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 30; $i++) {
            $this->createPersonInUnit($unit);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        Livewire::test('reports.persons')->set('perPage', 10)->assertStatus(200);

        $roster = $this->rowSelects($statements, 'persons');
        $this->assertNotEmpty($roster, 'the roster query must have run');

        foreach ($roster as $statement) {
            $this->assertStringContainsString('limit', strtolower($statement), "unbounded roster query: {$statement}");
        }
    }

    public function test_persons_empty_roster_renders_no_pagination_control(): void
    {
        // The user is attached to unitA (where their own person lives) but the
        // context is unitB, which holds nobody — so the roster is empty and
        // there is nothing to page through.
        $unitA = Unit::create(['name' => 'واحد الف']);
        $unitB = Unit::create(['name' => 'واحد ب']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'الف', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitA->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => bcrypt('password')]);
        $user->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => false]);

        $this->actingAs($user);
        session(['current_unit_id' => $unitB->id]);

        $component = Livewire::test('reports.persons')->assertStatus(200);
        $component->assertSee('موردی یافت نشد');

        $payload = $component->instance()->chartPayload();
        $this->assertSame([], $payload['persons']);
        $this->assertSame(1, $payload['personsPaginator']->lastPage());
        $this->assertFalse($payload['personsPaginator']->hasPages());
        $component->assertDontSeeHtml('aria-label="Pagination Navigation"');
    }

    // ==================== todos ====================

    public function test_todos_list_is_capped_to_one_page(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 1; $i <= 30; $i++) {
            $this->createTodoInUnit($unit, 'کار شماره '.$i);
        }
        $expectedTotal = Todo::where('unit_id', $unit->id)->count();

        $component = Livewire::test('reports.todos')
            ->set('perPage', 10)
            ->assertStatus(200);

        $this->assertSame(10, $this->renderedRowCount($component->html()));
        $component->assertSeeHtml('aria-label="Pagination Navigation"');

        $payload = $component->instance()->chartPayload();
        $this->assertCount(10, $payload['items'], 'the payload carries only the current page');
        $this->assertSame($expectedTotal, $payload['itemsPaginator']->total());
        $this->assertSame(
            $expectedTotal,
            $payload['completed'] + $payload['pending'],
            'the status counts must describe the whole set, not the page',
        );
        $this->assertSame($expectedTotal, array_sum($payload['byUnit']), 'the by-unit breakdown must cover the whole set');
    }

    public function test_todos_list_query_is_bounded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 1; $i <= 30; $i++) {
            $this->createTodoInUnit($unit, 'کار شماره '.$i);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        Livewire::test('reports.todos')->set('perPage', 10)->assertStatus(200);

        $roster = $this->rowSelects($statements, 'todos');
        $this->assertNotEmpty($roster, 'the list query must have run');

        foreach ($roster as $statement) {
            $this->assertStringContainsString('limit', strtolower($statement), "unbounded list query: {$statement}");
        }
    }

    // ==================== units ====================

    public function test_units_inventory_is_capped_to_one_page(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        // Children of the user's unit: the scope CTE includes descendants.
        for ($i = 1; $i <= 30; $i++) {
            Unit::create(['name' => 'واحد شماره '.$i, 'parent_id' => $unit->id]);
        }
        $expectedTotal = Unit::whereIn('id', [$unit->id, ...Unit::where('parent_id', $unit->id)->pluck('id')])->count();
        $this->assertGreaterThan(10, $expectedTotal);

        $component = Livewire::test('reports.units')
            ->set('perPage', 10)
            ->assertStatus(200);

        $this->assertSame(10, $this->renderedRowCount($component->html()));
        $component->assertSeeHtml('aria-label="Pagination Navigation"');

        $payload = $component->instance()->chartPayload();
        $this->assertCount(10, $payload['units'], 'the payload carries only the current page');
        $this->assertSame($expectedTotal, $payload['unitsPaginator']->total());
        $this->assertSame($expectedTotal, $payload['total'], 'the total must describe the whole set, not the page');
        $this->assertSame($expectedTotal, array_sum($payload['byType']), 'the by-type breakdown must cover the whole set');
    }

    public function test_units_inventory_query_is_bounded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 1; $i <= 30; $i++) {
            Unit::create(['name' => 'واحد شماره '.$i, 'parent_id' => $unit->id]);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        Livewire::test('reports.units')->set('perPage', 10)->assertStatus(200);

        $roster = $this->rowSelects($statements, 'units');
        $this->assertNotEmpty($roster, 'the inventory query must have run');

        foreach ($roster as $statement) {
            $this->assertStringContainsString('limit', strtolower($statement), "unbounded inventory query: {$statement}");
        }
    }
}
