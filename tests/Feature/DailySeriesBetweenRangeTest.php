<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\DailySeries;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #866 — `DailySeries::between()` kept only the *number* of days and threw
 * the range itself away, so `window()` rebuilt "N days ending today". Every past
 * window therefore charted the wrong axis, and `counts()`' LEFT JOIN silently
 * dropped every row that sat inside the range the user actually picked.
 *
 * These assertions are on the **content** of the axis (its first and last day),
 * not on its length. `assertCount` was blind to this bug precisely because the
 * rebuilt axis has the right length: 8 days asked for, 8 days returned — the
 * wrong 8.
 */
class DailySeriesBetweenRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_between_keeps_the_range_it_was_given(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

        $this->assertSame(
            ['2025-01-01', '2025-06-30'],
            DailySeries::between(Carbon::parse('2025-01-01'), Carbon::parse('2025-06-30'))->window(),
            'the window is the picked range, not "181 days ending today"',
        );
    }

    public function test_last_days_still_ends_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

        $this->assertSame(
            ['2026-09-09', '2026-10-08'],
            DailySeries::lastDays(30)->window(),
            'the ?days= path is unaffected and still ends today',
        );
    }

    public function test_single_day_range_is_that_one_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

        $this->assertSame(
            ['2026-03-15', '2026-03-15'],
            DailySeries::between(Carbon::parse('2026-03-15'), Carbon::parse('2026-03-15'))->window(),
            'one picked day must not become a window ending today',
        );
    }

    public function test_between_is_clamped_to_max_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

        // The ?days= path already rejects > MAX_DAYS through ReportDays; the two
        // picker pages pass free-text Jalali dates straight through, so the
        // service clamps rather than letting one user build a 14610-point axis
        // (one Highcharts point per generated row).
        [$from, $to] = DailySeries::between(Carbon::parse('1986-10-09'), Carbon::parse('2026-10-08'))->window();

        $this->assertLessThanOrEqual(
            DailySeries::MAX_DAYS,
            (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
            'a 40-year range must not materialise 14610 chart points',
        );
        $this->assertSame('2026-10-08', $to, 'the clamp keeps the end the user picked');
    }

    public function test_counts_keeps_rows_inside_a_far_past_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));

        $user = User::factory()->create();
        $unit = Unit::factory()->create();

        foreach (['2025-01-01' => 'OLD', '2025-03-01' => 'MID', '2026-10-01' => 'RECENT'] as $day => $code) {
            $ticket = Ticket::create([
                'ticket_code' => $code,
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'subject' => 'گزارش',
                'content' => 'متن',
                'priority' => 'normal',
                'status' => 'created',
            ]);

            $ticket->forceFill([
                'created_at' => Carbon::parse($day)->startOfDay(),
                'updated_at' => Carbon::parse($day)->startOfDay(),
            ])->saveQuietly();
        }

        $counts = DailySeries::between(Carbon::parse('2025-01-01'), Carbon::parse('2025-03-02'))
            ->counts(Ticket::query(), 'created_at');

        $days = array_column($counts, 'day');

        $this->assertSame('2025-01-01', $days[0], 'the axis starts on the picked from-day');
        $this->assertSame('2025-03-02', end($days), 'the axis ends on the picked to-day');
        $this->assertSame(2, array_sum(array_column($counts, 'count')), 'the rows inside the range survive the LEFT JOIN');
        $this->assertArrayNotHasKey(
            '2026-10-01',
            array_flip($days),
            'a row outside the picked range is not on the axis at all',
        );
    }
}
