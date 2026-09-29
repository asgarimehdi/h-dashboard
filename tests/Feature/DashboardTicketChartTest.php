<?php

use App\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Issue #734 — the 30-day ticket trend chart returned the OLDEST 30 days with
 * data, because `orderBy('day')` was applied before `limit(30)`.
 *
 * The window is asserted on the Jalali `m/d` labels the component actually
 * renders, compared against an ordered list built in PHP, so one expect()
 * covers content, count and display order together.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    ['user' => $this->user, 'unit' => $this->unit] = $this->createUserWithUnit();
    $this->actingAs($this->user);
});

/**
 * One ticket on the given day. `created_at` is not fillable, so it is written
 * with forceFill after the row exists.
 *
 * Noon avoids a day-boundary flip: the app runs on Asia/Tehran while the
 * Postgres session is UTC, and `date(created_at)` buckets in the session zone.
 */
function createTicketOnDay($unit, $user, Carbon $day): Ticket
{
    $ticket = Ticket::create([
        'ticket_code' => 'TC'.fake()->unique()->numerify('######'),
        'user_id' => $user->id,
        'unit_id' => $unit->id,
        'subject' => 'تیکت تست',
        'content' => 'محتوای تست',
        'priority' => 'normal',
        'status' => 'created',
    ]);

    $ticket->forceFill(['created_at' => $day, 'updated_at' => $day])->save();

    return $ticket;
}

/** Create one ticket per day for the `span` days ending today. */
function seedTicketPerDay($unit, $user, int $span): void
{
    foreach (range(0, $span - 1) as $offset) {
        createTicketOnDay($unit, $user, now()->subDays($offset)->setTime(12, 0, 0));
    }
}

/** The Jalali `m/d` label the component produces for a day. */
function chartLabelForDay(Carbon $day): string
{
    return Jalalian::fromCarbon($day->startOfDay())->format('m/d');
}

/** The labels the chart must show, oldest first, for the most recent N days. */
function expectedRecentDayLabels(int $days = 30): array
{
    return collect(range(0, $days - 1))
        ->map(fn (int $offset) => chartLabelForDay(now()->subDays($offset)))
        ->reverse()
        ->values()
        ->all();
}

test('trend chart shows the 30 most recent days with data, oldest first', function () {
    seedTicketPerDay($this->unit, $this->user, 39);

    $data = Livewire::test('dashboard')->instance()->ticketChartData;

    expect($data['categories'])->toBe(expectedRecentDayLabels());
    expect($data['series'])->toHaveCount(30);
});

test('trend chart keeps every category when fewer than 30 days have data', function () {
    seedTicketPerDay($this->unit, $this->user, 5);

    $data = Livewire::test('dashboard')->instance()->ticketChartData;

    expect($data['categories'])->toBe(expectedRecentDayLabels(5));
});

test('trend chart counts several tickets on the same day as one bucket', function () {
    seedTicketPerDay($this->unit, $this->user, 2);

    createTicketOnDay($this->unit, $this->user, now()->setTime(12, 0, 0));

    $data = Livewire::test('dashboard')->instance()->ticketChartData;

    expect($data['series'])->toBe([1, 2]);
});
