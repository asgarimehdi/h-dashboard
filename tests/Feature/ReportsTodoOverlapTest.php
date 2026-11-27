<?php

namespace Tests\Feature;

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
 * Issue #1942 — both todo reports filtered the window on `start_at` alone, so a
 * multi-day todo that began before `from` but was still live inside the window
 * vanished from the counts and the row list. Window membership is overlap:
 * `start_at <= to AND (end_at IS NULL OR end_at >= from)`. `end_at IS NULL` is
 * open-ended (#1792), and `byDay` still counts on `start_at`.
 *
 * The same matrix is pinned against the dedicated todos report and the
 * advanced report's todos branch so the two surfaces cannot drift apart again.
 */
#[CoversNothing]
class ReportsTodoOverlapTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-27 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        // A past window, so an `end_at` inside it is genuinely overdue.
        $this->from = now()->subDays(10)->startOfDay();
        $this->to = now()->subDays(1)->endOfDay();
    }

    private function jalali(Carbon $date): string
    {
        return Jalalian::fromCarbon($date)->format('Y/m/d');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTodo(Unit $unit, array $attributes): Todo
    {
        return Todo::create(array_merge([
            'title' => 'وظیفه',
            'unit_id' => $unit->id,
            'is_completed' => false,
        ], $attributes));
    }

    /**
     * The overlap matrix. A, C, E and F belong to the window; B ended before it
     * and D starts after it.
     *
     * @return array<string, Todo>
     */
    private function createWindowFixture(Unit $unit): array
    {
        return [
            // A — started before `from`, still live in the window, now overdue.
            'a' => $this->createTodo($unit, [
                'title' => 'overlapping-overdue',
                'start_at' => $this->from->copy()->subDays(10),
                'end_at' => $this->from->copy()->addDays(2),
            ]),
            // B — ended before the window opened.
            'b' => $this->createTodo($unit, [
                'title' => 'ended-before-window',
                'start_at' => $this->from->copy()->subDays(20),
                'end_at' => $this->from->copy()->subDays(5),
            ]),
            // C — starts inside the window and runs past `to`.
            'c' => $this->createTodo($unit, [
                'title' => 'runs-past-window',
                'start_at' => $this->from->copy()->addDay(),
                'end_at' => $this->to->copy()->addDays(10),
            ]),
            // D — starts after the window closed, open-ended.
            'd' => $this->createTodo($unit, [
                'title' => 'starts-after-window',
                'start_at' => $this->to->copy()->addDays(5),
                'end_at' => null,
            ]),
            // E — open-ended todo that started before the window.
            'e' => $this->createTodo($unit, [
                'title' => 'open-ended-before-window',
                'start_at' => $this->from->copy()->subDays(30),
                'end_at' => null,
            ]),
            // F — completed before the window opened but still live inside it.
            'f' => $this->createTodo($unit, [
                'title' => 'completed-overlap',
                'start_at' => $this->from->copy()->subDays(3),
                'end_at' => $this->from->copy()->addDay(),
                'is_completed' => true,
            ]),
        ];
    }

    public function test_dedicated_report_counts_multi_day_todos_that_started_before_the_window(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $todos = $this->createWindowFixture($unit);

        $payload = Livewire::test('reports.todos')
            ->set('dateFrom', $this->jalali($this->from))
            ->set('dateTo', $this->jalali($this->to))
            ->instance()->chartPayload();

        $this->assertSame(1, $payload['completed'], 'F is completed inside the window');
        $this->assertSame(3, $payload['pending'], 'A, C and E are open work inside the window');
        $this->assertSame(1, $payload['overdue'], 'only A is past due inside the window');

        $this->assertEqualsCanonicalizing(
            [$todos['a']->id, $todos['c']->id, $todos['e']->id, $todos['f']->id],
            array_column($payload['items'], 'id'),
            'the row list is the overlap set, not the start-inside set',
        );
        $this->assertNotContains($todos['b']->id, array_column($payload['items'], 'id'), 'B ended before the window');
        $this->assertNotContains($todos['d']->id, array_column($payload['items'], 'id'), 'D starts after the window');
    }

    public function test_dedicated_report_by_day_stays_start_at_anchored_under_overlap(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->createWindowFixture($unit);

        $payload = Livewire::test('reports.todos')
            ->set('dateFrom', $this->jalali($this->from))
            ->set('dateTo', $this->jalali($this->to))
            ->instance()->chartPayload();

        // Only C starts on a day the axis materialises; A, E and F start before
        // `from` and therefore have no column even though they are counted.
        $this->assertSame(1, array_sum(array_column($payload['byDay'], 'count')));

        $byDay = collect($payload['byDay'])->pluck('count', 'day')->toArray();
        $this->assertSame(1, $byDay[$this->jalali($this->from->copy()->addDay())] ?? 0, 'C lands on its start day');
    }

    public function test_advanced_report_todos_branch_counts_multi_day_todos_that_started_before_the_window(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->createWindowFixture($unit);

        $data = Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('dateFrom', $this->jalali($this->from))
            ->set('dateTo', $this->jalali($this->to))
            ->instance()->reportData();

        $this->assertSame(4, $data['total'], 'A, C, E and F belong to the window');
        $this->assertSame(1, array_sum(array_column($data['byDay'], 'count')), 'byDay stays start_at anchored');
        $this->assertSame([4], array_values($data['byUnit']), 'byUnit rides the same overlap-filtered query');
    }
}
