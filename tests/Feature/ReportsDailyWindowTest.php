<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Todo;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #736 — the UI side of the one-window rule.
 *
 * The dashboard ticket trend clipped with `ORDER BY day ASC` + `LIMIT 30`, which
 * returns the *oldest* 30 days, so recent days were missing from the chart
 * entirely. The report pages grouped the whole history, so the same chart
 * looked different depending on where it was rendered.
 */
class ReportsDailyWindowTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * `created_at` is not fillable on Ticket, so it is set after the insert.
     */
    protected function createTicketAt($user, string $code, \DateTimeInterface $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'ticket_code' => $code,
            'user_id' => $user->id,
            'unit_id' => $user->units()->first()->id,
            'subject' => 'روند',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $ticket->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $ticket->refresh();
    }

    public function test_dashboard_ticket_chart_shows_the_most_recent_days_not_the_oldest(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        // 40 distinct days: more than the 30-day window, so a LIMIT applied
        // before the ORDER BY would keep the wrong end of the range.
        for ($i = 0; $i < 40; $i++) {
            $this->createTicketAt($user, 'TREND-'.$i, now()->subDays(39 - $i)->startOfDay()->addHours(9));
        }

        $data = Livewire::test('dashboard')->instance()->getTicketChartDataProperty();

        $this->assertCount(30, $data['categories'], 'the chart is clipped to the 30-day window');
        $this->assertSame(30, array_sum($data['series']), 'only 30 of the 40 days are shown');

        // The window must END today. With `ORDER BY day ASC` + `LIMIT 30` the
        // first category is 10 days ago and the last is 39 days ago — the
        // opposite, which is exactly the bug this asserts against.
        $today = Jalalian::fromCarbon(now())->format('m/d');
        $windowStart = Jalalian::fromCarbon(now()->subDays(29))->format('m/d');

        $this->assertSame($today, end($data['categories']), 'the newest day in the chart is today');
        $this->assertSame($windowStart, reset($data['categories']), 'the oldest day in the chart is 29 days ago');
    }

    public function test_dashboard_ticket_chart_keeps_ascending_display_order(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->createTicketAt($user, 'OLD', now()->subDays(5)->startOfDay());
        $this->createTicketAt($user, 'NEW', now()->startOfDay());

        Cache::flush();
        $data = Livewire::test('dashboard')->instance()->getTicketChartDataProperty();

        // Exactly the 30 labels of the window, oldest first — the whole
        // sequence, so a reversed order or a different window both fail here.
        $expected = [];
        for ($i = 29; $i >= 0; $i--) {
            $expected[] = Jalalian::fromCarbon(now()->subDays($i))->format('m/d');
        }

        $this->assertSame($expected, $data['categories'], 'oldest first, so the line reads left to right');
        $this->assertSame(1, $data['series'][24], 'the 5-days-ago ticket sits in its own slot');
        $this->assertSame(1, $data['series'][29], 'today holds the newest ticket');
        $this->assertSame(2, array_sum($data['series']));
    }

    public function test_dashboard_ticket_chart_includes_days_without_tickets_as_zero(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->createTicketAt($user, 'ONE', now()->startOfDay());

        Cache::flush();
        $data = Livewire::test('dashboard')->instance()->getTicketChartDataProperty();

        $this->assertCount(30, $data['categories'], 'every day in the window has a slot');
        $this->assertSame(1, array_sum($data['series']), 'only the one real ticket is counted');
        $this->assertContains(0, $data['series'], 'a day with no tickets is a zero, not a gap');
        $this->assertSame(29, count(array_keys($data['series'], 0, true)), 'the other 29 days are zero');
    }

    public function test_reports_todos_by_day_fills_empty_days_over_the_chosen_range(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $from = now()->subDays(6);
        $to = now()->addDays(1);

        // end_at is set because the page's own "to" filter is `end_at <= to`,
        // which excludes todos with no due date.
        Todo::create([
            'title' => 'یک',
            'unit_id' => $user->units()->first()->id,
            'is_completed' => false,
            'start_at' => now()->startOfDay(),
            'end_at' => now()->startOfDay(),
        ]);

        $component = Livewire::test('reports.todos')
            ->set('dateFrom', Jalalian::fromCarbon($from)->format('Y/m/d'))
            ->set('dateTo', Jalalian::fromCarbon($to)->format('Y/m/d'));

        $byDay = $component->instance()->chartPayload()['byDay'];

        $this->assertCount(8, $byDay, 'from..to inclusive is 8 days and all 8 are present');
        $this->assertSame(1, array_sum(array_column($byDay, 'count')));
        $this->assertSame(7, count(array_filter(array_column($byDay, 'count'), fn ($c) => $c === 0)));
    }

    public function test_reports_advanced_by_day_fills_empty_days_over_the_chosen_range(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $this->createTicketAt($user, 'ADV-1', now()->startOfDay());

        $from = now()->subDays(4);
        $to = now()->subDays(1);

        $component = Livewire::test('reports.advanced')
            ->set('dateFrom', Jalalian::fromCarbon($from)->format('Y/m/d'))
            ->set('dateTo', Jalalian::fromCarbon($to)->format('Y/m/d'));

        $byDay = $component->instance()->chartPayload()['byDay'];

        $this->assertCount(4, $byDay, 'the 4-day range is fully materialised');
        $this->assertSame([0, 0, 0, 0], array_column($byDay, 'count'), 'no tickets in the range at all');
    }
}
