<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\Unit;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Morilog\Jalali\Jalalian;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1792 — the todos report's range filter was `end_at <= to` with no
 * null arm, so every todo with no end date vanished from completed/pending,
 * the daily series and the row list. `end_at = null` means open-ended, not
 * out of range — and it is still never overdue.
 *
 * Issue #1942 — the window is now overlap (`start_at <= to` and not ended
 * before `from`), so `test_todo_ending_after_the_range_is_excluded` became
 * `..._is_included`; the null arm and `byDay`'s `start_at` anchor stay.
 */
class TodoReportTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return Livewire::test('reports.todos')->instance()->chartPayload();
    }

    /**
     * @param  array<int, array{day: string, count: int}>  $byDay
     */
    private function byDayCountFor(array $byDay, \DateTimeInterface $day): int
    {
        $label = Jalalian::fromCarbon(Carbon::parse($day))->format('Y/m/d');

        foreach ($byDay as $row) {
            if ($row['day'] === $label) {
                return $row['count'];
            }
        }

        $this->fail("day {$label} is not on the daily series axis");
    }

    public function test_dateless_pending_todo_is_counted_and_lands_on_its_start_day(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $start = now()->subDays(3)->startOfDay();
        $todo = $this->createTodo($unit, ['start_at' => $start, 'end_at' => null]);

        $payload = $this->payload();

        $this->assertSame(1, $payload['pending'], 'a dateless pending todo is in the range');
        $this->assertSame(0, $payload['completed']);
        $this->assertSame([$todo->id], array_column($payload['items'], 'id'), 'and it is in the row list');
        $this->assertSame(
            1,
            $this->byDayCountFor($payload['byDay'], $start),
            'a dateless todo lands on its own start day, not on a phantom day',
        );
    }

    public function test_dateless_completed_todo_is_counted_in_completed(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->createTodo($unit, [
            'start_at' => now()->subDays(3)->startOfDay(),
            'end_at' => null,
            'is_completed' => true,
        ]);

        $payload = $this->payload();

        $this->assertSame(1, $payload['completed']);
        $this->assertSame(0, $payload['pending']);
    }

    public function test_dateless_todo_is_never_overdue(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->createTodo($unit, [
            'start_at' => now()->subDays(3)->startOfDay(),
            'end_at' => null,
        ]);

        $payload = $this->payload();

        $this->assertSame(0, $payload['overdue'], 'no end date means never overdue');
        $this->assertSame(1, $payload['pending'], 'but it is still open work');
    }

    public function test_todo_ending_after_the_range_is_included(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // #1942 — overlap supersedes #1792's containment guard: running past
        // `to` is not a reason to drop a todo that is live inside the window.
        $todo = $this->createTodo($unit, [
            'start_at' => now()->subDays(2)->startOfDay(),
            'end_at' => now()->addDays(60),
        ]);

        $payload = $this->payload();

        $this->assertSame(1, $payload['pending']);
        $this->assertSame(0, $payload['completed']);
        $this->assertSame([$todo->id], array_column($payload['items'], 'id'));
    }

    public function test_todo_ending_before_the_range_is_excluded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // Default mount window opens at now - 30 days; this one closed long before.
        $this->createTodo($unit, [
            'start_at' => now()->subDays(60)->startOfDay(),
            'end_at' => now()->subDays(40),
        ]);

        $payload = $this->payload();

        $this->assertSame(0, $payload['completed'] + $payload['pending'], 'a todo that ended before `from` is outside');
        $this->assertSame([], $payload['items']);
    }

    public function test_todo_ending_inside_the_range_is_counted(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $this->createTodo($unit, [
            'start_at' => now()->subDays(2)->startOfDay(),
            'end_at' => now()->addDays(5),
        ]);

        $this->assertSame(1, $this->payload()['pending']);
    }

    public function test_dateless_todo_starting_after_the_range_is_excluded(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        // Default mount window ends today + 30 days.
        $this->createTodo($unit, [
            'start_at' => now()->addDays(40)->startOfDay(),
            'end_at' => null,
        ]);

        $payload = $this->payload();

        $this->assertSame(0, $payload['completed'] + $payload['pending'], 'outside the window is outside the counts');
        $this->assertSame([], $payload['items']);
        $this->assertSame(0, array_sum(array_column($payload['byDay'], 'count')), 'and it has no day on the axis');
    }

    public function test_dateless_todo_respects_the_unit_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->actingAs($user);

        $visible = $this->createTodo($unit, [
            'start_at' => now()->subDays(3)->startOfDay(),
            'end_at' => null,
        ]);

        // A unit the user is not attached to — outside AccessibleUnitIds().
        $otherUnit = Unit::factory()->create();
        $this->createTodo($otherUnit, [
            'start_at' => now()->subDays(3)->startOfDay(),
            'end_at' => null,
        ]);

        $payload = $this->payload();

        $this->assertSame(1, $payload['pending'], 'the null-arm stays inside the scope predicate');
        $this->assertSame([$visible->id], array_column($payload['items'], 'id'));
    }
}
