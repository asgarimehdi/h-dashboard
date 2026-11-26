<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1794 — the advanced report filtered and charted todos by `created_at`,
 * so a todo that started yesterday but was created 40 days ago vanished from
 * the default 30-day window, and a todo scheduled for next month appeared
 * today. A todo's report date is its `start_at`; the tickets branch keeps
 * `created_at`.
 *
 * The dedicated todos report (`reports.todos`) already filters on `start_at`,
 * so the cross-check pins the two reports against each other on one explicit
 * range — on `total`, `byDay` and, since #1858, `byUnit`. The unit comparison
 * is only valid while the fixtures keep every `end_at` inside the window and
 * no unit filter is applied: the advanced report lacks the #1792 `end_at` null
 * arm and its unit filter is hierarchical, while the todos report's is flat.
 */
#[CoversNothing]
class ReportsAdvancedTodoDateColumnTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    private function report(string $type, ?string $from = null, ?string $to = null): array
    {
        $component = Livewire::test('reports.advanced')->set('reportType', $type);
        if ($from !== null) {
            $component->set('dateFrom', $from);
        }
        if ($to !== null) {
            $component->set('dateTo', $to);
        }

        return $component->instance()->reportData();
    }

    private function jalali(Carbon $date): string
    {
        return Jalalian::fromCarbon($date)->format('Y/m/d');
    }

    public function test_old_todo_that_started_inside_the_default_range_is_counted_on_its_start_day(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        // created_at deliberately 40 days ago: the old created_at filter dropped it.
        $todo->forceFill([
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ])->saveQuietly();

        $data = $this->report('todos');

        $this->assertSame(1, $data['total']);

        $byDay = collect($data['byDay'])->pluck('count', 'day')->toArray();
        $this->assertSame(1, $byDay[$this->jalali(now()->subDay())], 'the count lands on the start day');
        $this->assertSame(0, $byDay[$this->jalali(now())], 'the creation day carries nothing');
    }

    public function test_todo_created_today_but_starting_next_month_is_excluded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => now()->addMonth(),
            'end_at' => now()->addMonths(2),
        ]);

        $data = $this->report('todos');

        $this->assertSame(0, $data['total']);
        $this->assertSame(0, array_sum(array_column($data['byDay'], 'count')));
    }

    public function test_ticket_created_today_still_lands_on_the_creation_day(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        Ticket::factory()->create(['unit_id' => $unit->id]);

        $data = $this->report('tickets');

        $this->assertSame(1, $data['total']);

        $byDay = collect($data['byDay'])->pluck('count', 'day')->toArray();
        $this->assertSame(1, $byDay[$this->jalali(now())], 'the tickets branch keeps created_at');
    }

    public function test_advanced_todos_branch_agrees_with_the_dedicated_todos_report(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $from = now()->subDays(10);
        $to = now();

        // A child unit in scope, so the unit buckets are not trivially identical.
        $child = Unit::factory()->create(['parent_id' => $unit->id]);

        // A non-null end_at inside the range: the null/late end_at divergence
        // between the two reports belongs to #1792, not to this fix.
        $old = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay(),
        ]);
        $old->forceFill([
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ])->saveQuietly();

        Todo::factory()->completed()->create([
            'unit_id' => $child->id,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2),
        ]);

        // The two components have different default ranges, so the range is explicit.
        $advanced = $this->report('todos', $this->jalali($from), $this->jalali($to));

        $dedicated = Livewire::test('reports.todos')
            ->set('dateFrom', $this->jalali($from))
            ->set('dateTo', $this->jalali($to))
            ->instance()->chartPayload();

        // `overdue` is a subset of `pending` in this payload (both are
        // `is_completed = false`), so the range total is completed + pending —
        // adding overdue would double-count the past-due row.
        $dedicatedTotal = $dedicated['completed'] + $dedicated['pending'];

        $this->assertSame(2, $advanced['total']);
        $this->assertSame(2, $dedicatedTotal);
        $this->assertSame(
            array_sum(array_column($advanced['byDay'], 'count')),
            array_sum(array_column($dedicated['byDay'], 'count')),
        );

        $dedicatedUnits = $dedicated['byUnit'];
        $advancedUnits = $advanced['byUnit'];
        ksort($dedicatedUnits);
        ksort($advancedUnits);

        $this->assertSame($advancedUnits, $dedicatedUnits, 'both reports must bucket the same range by unit');
        $this->assertSame(2, array_sum($dedicatedUnits), 'and cover the whole range total');
    }
}
