<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportController;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #736 — one definition for the `by_day` window.
 *
 * 1) `?days=` (default 30) applies to every `by_day` aggregate, so the UI and
 *    the API stop disagreeing about what window a daily chart covers.
 * 2) Days with no rows are present with a zero count instead of vanishing —
 *    a missing column reads as "no data" rather than "zero that day".
 */
covers(ReportController::class);

class ReportsByDayRangeTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Session::flush();
    }

    /**
     * `created_at` is not fillable on Ticket, so it is set explicitly after the
     * insert — passing it to create() would be silently dropped and every ticket
     * would land on today, making the window assertions pass for the wrong
     * reason.
     */
    protected function createTicket(User $user, string $code, \DateTimeInterface $createdAt): Ticket
    {
        $ticket = Ticket::create([
            'ticket_code' => $code,
            'user_id' => $user->id,
            'unit_id' => $user->units()->first()->id,
            'subject' => 'گزارش',
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

    protected function createTodo(User $user, string $title, \DateTimeInterface $startAt): Todo
    {
        return Todo::create([
            'title' => $title,
            'unit_id' => $user->units()->first()->id,
            'is_completed' => false,
            'start_at' => $startAt,
        ]);
    }

    /** Jalali label for a day offset, matching the API's `Y/m/d` output. */
    protected function jalaliDay(int $daysAgo): string
    {
        return Jalalian::fromCarbon(now()->subDays($daysAgo)->startOfDay())->format('Y/m/d');
    }

    public function test_tickets_by_day_defaults_to_thirty_days(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTicket($user, 'OLD-1', now()->subDays(45));
        $this->createTicket($user, 'NEW-1', now()->subDays(2));

        $response = $this->apiGet('/api/reports/tickets', $token);

        $response->assertStatus(200);
        $days = array_column($response->json('by_day'), 'day');

        $this->assertContains($this->jalaliDay(2), $days, 'today-ish ticket is inside the default window');
        $this->assertNotContains($this->jalaliDay(45), $days, 'a 45-day-old ticket is outside the default 30-day window');
    }

    public function test_tickets_by_day_honours_a_custom_days_parameter(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTicket($user, 'OLD-1', now()->subDays(45));
        $this->createTicket($user, 'NEW-1', now()->subDays(2));

        $response = $this->apiGet('/api/reports/tickets?days=60', $token);

        $response->assertStatus(200);
        $days = array_column($response->json('by_day'), 'day');

        $this->assertContains($this->jalaliDay(45), $days, 'a 45-day-old ticket is inside a 60-day window');
        $this->assertContains($this->jalaliDay(2), $days);
    }

    public function test_tickets_by_day_contains_every_day_in_the_window_with_a_zero_count(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTicket($user, 'T-1', now()->subDays(1));
        $this->createTicket($user, 'T-2', now());

        $response = $this->apiGet('/api/reports/tickets?days=5', $token);

        $response->assertStatus(200);
        $byDay = $response->json('by_day');

        $this->assertCount(5, $byDay, 'the window is 5 days and every one of them is present');
        $this->assertSame(
            [
                $this->jalaliDay(4),
                $this->jalaliDay(3),
                $this->jalaliDay(2),
                $this->jalaliDay(1),
                $this->jalaliDay(0),
            ],
            array_column($byDay, 'day'),
            'days are ascending, oldest first'
        );
        $this->assertSame(
            [0, 0, 0, 1, 1],
            array_map('intval', array_column($byDay, 'count')),
            'the three empty days are zero, not missing'
        );
    }

    public function test_tickets_by_day_window_is_inclusive_of_today(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTicket($user, 'T-1', now());

        $response = $this->apiGet('/api/reports/tickets?days=1', $token);

        $response->assertStatus(200);
        $byDay = $response->json('by_day');
        $this->assertCount(1, $byDay);
        $this->assertSame($this->jalaliDay(0), $byDay[0]['day']);
        $this->assertSame(1, (int) $byDay[0]['count']);
    }

    public function test_todos_by_day_honours_the_same_window_as_tickets(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTodo($user, 'قدیمی', now()->subDays(40));
        $this->createTodo($user, 'اخیر', now()->subDays(1));

        $response = $this->apiGet('/api/reports/todos', $token);

        $response->assertStatus(200);
        $days = array_column($response->json('by_day'), 'day');

        $this->assertContains($this->jalaliDay(1), $days);
        $this->assertNotContains($this->jalaliDay(40), $days, 'todos obey the same default window as tickets');
    }

    public function test_todos_by_day_fills_empty_days(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTodo($user, 'یک', now()->subDays(1));

        $response = $this->apiGet('/api/reports/todos?days=3', $token);

        $response->assertStatus(200);
        $byDay = $response->json('by_day');

        $this->assertCount(3, $byDay);
        $this->assertSame(
            [0, 1, 0],
            array_map('intval', array_column($byDay, 'count')),
            'the days without todos are zero'
        );
    }

    public function test_by_day_rejects_a_non_positive_window(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->apiGet('/api/reports/tickets?days=0', $token)->assertStatus(422);
        $this->apiGet('/api/reports/tickets?days=-5', $token)->assertStatus(422);
    }

    public function test_by_day_rejects_a_window_above_the_cap(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->apiGet('/api/reports/tickets?days=5000', $token)->assertStatus(422);
    }

    public function test_by_day_clamps_a_nonsense_value_instead_of_erroring(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user);

        $this->createTicket($user, 'T-1', now());

        $response = $this->apiGet('/api/reports/tickets?days=abc', $token);

        $response->assertStatus(200);
        $this->assertCount(30, $response->json('by_day'), 'a non-numeric value falls back to the 30-day default');
    }
}
