<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1872 — the null-unit contract (#838) says a unit-less todo belongs to
 * its creator. The calendar and the todo list honoured it, but every aggregate
 * re-spelled the scope as a bare `whereIn('unit_id', $accessibleIds)`, so a
 * null-unit todo never matched: the calendar showed the row while the
 * dashboard and every report counted zero.
 *
 * These tests pin the contract at all four aggregate levels (API, advanced
 * report, todo report, dashboard), the absence of cross-user leakage, and the
 * per-user cache key that makes a null-unit todo of user A invisible to user B
 * even when both share the exact same unit scope.
 */
#[CoversNothing]
class NullUnitTodoAggregatesTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const UNIT_NAME = 'واحد اسکوپ تست';

    protected function setUp(): void
    {
        parent::setUp();

        // A frozen clock keeps the Jalali default range and "today" stable.
        $this->travelTo(Carbon::parse('2026-11-26 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Session::flush();
    }

    /**
     * A `manage_personnel` account, optionally attached to `$unit`. A null
     * `$unit` means `AccessService::accessibleUnitIds()` is `[]`.
     */
    private function makeUser(?Unit $unit, string $nCode): User
    {
        Person::create([
            'n_code' => $nCode, 'f_name' => 'کاربر', 'l_name' => $nCode,
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
            'u_id' => $unit?->id,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('manage_personnel');

        if ($unit) {
            $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTodo(?User $owner, ?Unit $unit, array $attributes): Todo
    {
        return Todo::create([
            'title' => 'وظیفه تست',
            'unit_id' => $unit?->id,
            'user_id' => $owner?->id,
            ...$attributes,
        ]);
    }

    public function test_null_unit_todos_count_in_every_aggregate_and_leak_nothing(): void
    {
        $user = $this->makeUser(null, '1111111111');
        $this->actingAs($user);

        $this->assertSame(
            [],
            app(AccessService::class)->accessibleUnitIds(),
            'the fixture must really be an empty scope',
        );
        $this->assertTrue($user->hasPermissionTo('manage_personnel'));

        // Two of the user's own unit-less todos: one pending, one completed.
        $this->makeTodo($user, null, [
            'is_completed' => false,
            'start_at' => now()->startOfDay(),
            'end_at' => null,
        ]);
        $this->makeTodo($user, null, [
            'is_completed' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay(),
        ]);

        // A foreign unit row that must stay out of every aggregate.
        $foreignUnit = Unit::create(['name' => 'واحد بیرونی تست']);
        $this->makeTodo(null, $foreignUnit, [
            'is_completed' => false,
            'start_at' => now()->startOfDay(),
            'end_at' => now(),
        ]);

        // 1) API report.
        $token = $this->createApiToken($user);
        $response = $this->apiGet('/api/reports/todos', $token)->assertOk();
        $this->assertSame(1, $response->json('completed'));
        $this->assertSame(1, $response->json('pending'));
        $this->assertSame(0, $response->json('overdue'));
        $this->assertSame(['نامشخص' => 2], $response->json('by_unit'));

        // 2) Advanced report.
        $advanced = Livewire::test('reports.advanced')->set('reportType', 'todos');
        $advancedData = $advanced->instance()->reportData();
        $this->assertSame(2, $advancedData['total']);
        $this->assertSame(['نامشخص' => 2], $advancedData['byUnit']);

        // 3) Todo report (aggregate payload + rows).
        $todos = Livewire::test('reports.todos')->assertOk();
        $payload = $todos->instance()->chartPayload();
        $this->assertSame(1, $payload['completed']);
        $this->assertSame(1, $payload['pending']);
        $this->assertSame(0, $payload['overdue']);
        $this->assertSame(['نامشخص' => 2], $payload['byUnit']);
        $this->assertCount(2, $payload['items']);

        // 4) Dashboard — no early return zeroes the todos any more.
        $dashboard = Livewire::test('dashboard')->assertOk();
        $this->assertSame(2, $dashboard->get('totalTodos'));
        $this->assertSame(1, $dashboard->get('pendingTodos'));
        $this->assertSame(1, $dashboard->get('completedTodos'));
        $this->assertSame(0, $dashboard->get('linkedTodos'));
        $this->assertSame(2, $dashboard->get('todayTodos'));
        // Everything else still fails closed on an empty scope.
        $this->assertSame(0, $dashboard->get('totalPersons'));
        $this->assertSame(0, $dashboard->get('totalUnits'));
        $this->assertSame(0, $dashboard->get('totalTickets'));
    }

    public function test_two_users_with_the_same_scope_do_not_share_todo_aggregates(): void
    {
        $unit = Unit::create(['name' => self::UNIT_NAME]);
        $userA = $this->makeUser($unit, '2222222222');
        $userB = $this->makeUser($unit, '3333333333');

        // Same unit scope and therefore the same scope hash for both users.
        $this->actingAs($userA);
        $scopeA = app(AccessService::class)->accessibleUnitIds();
        $this->actingAs($userB);
        $scopeB = app(AccessService::class)->accessibleUnitIds();
        $this->assertSame([$unit->id], $scopeA);
        $this->assertSame($scopeA, $scopeB);

        // A owns two unit-less todos, B one. Every fixture row is written
        // before the first read so nothing bumps a cache version between the
        // two users' calls below — otherwise this test would prove nothing.
        $this->makeTodo($userA, null, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);
        $this->makeTodo($userA, null, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);
        $this->makeTodo($userB, null, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);

        $tokenA = $this->createApiToken($userA);
        $tokenB = $this->createApiToken($userB);

        // API — A warms the cache, B must not read A's numbers.
        $this->actingAs($userA);
        $this->assertSame(2, $this->apiGet('/api/reports/todos', $tokenA)->assertOk()->json('pending'));
        $this->assertSame(['نامشخص' => 2], $this->apiGet('/api/reports/todos', $tokenA)->json('by_unit'));

        $this->actingAs($userB);
        $this->assertSame(1, $this->apiGet('/api/reports/todos', $tokenB)->assertOk()->json('pending'));
        $this->assertSame(['نامشخص' => 1], $this->apiGet('/api/reports/todos', $tokenB)->json('by_unit'));

        // Advanced report.
        $this->actingAs($userA);
        $this->assertSame(2, Livewire::test('reports.advanced')->set('reportType', 'todos')->instance()->reportData()['total']);

        $this->actingAs($userB);
        $this->assertSame(1, Livewire::test('reports.advanced')->set('reportType', 'todos')->instance()->reportData()['total']);

        // Todo report.
        $this->actingAs($userA);
        $this->assertSame(2, Livewire::test('reports.todos')->instance()->chartPayload()['pending']);

        $this->actingAs($userB);
        $this->assertSame(1, Livewire::test('reports.todos')->instance()->chartPayload()['pending']);

        // Dashboard.
        $this->actingAs($userA);
        $this->assertSame(2, Livewire::test('dashboard')->get('totalTodos'));

        $this->actingAs($userB);
        $this->assertSame(1, Livewire::test('dashboard')->get('totalTodos'));
    }

    public function test_unit_user_counts_their_unit_and_their_own_null_unit_todos(): void
    {
        $unit = Unit::create(['name' => self::UNIT_NAME]);
        $foreignUnit = Unit::create(['name' => 'واحد بیرونی تست']);
        $user = $this->makeUser($unit, '4444444444');
        $other = $this->makeUser($foreignUnit, '5555555555');

        $this->actingAs($user);
        $this->assertSame([$unit->id], app(AccessService::class)->accessibleUnitIds());

        $this->makeTodo($user, $unit, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);
        $this->makeTodo($user, null, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);
        // Out of scope for `$user`, in or out of `byUnit` depending on the leak.
        $this->makeTodo(null, $foreignUnit, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);
        $this->makeTodo($other, null, ['is_completed' => false, 'start_at' => now()->startOfDay(), 'end_at' => null]);

        $assertByUnit = function (array $byUnit): void {
            $this->assertCount(2, $byUnit, 'only the unit row and the user-owned null-unit row');
            $this->assertSame(1, $byUnit[self::UNIT_NAME] ?? null);
            $this->assertSame(1, $byUnit['نامشخص'] ?? null);
        };

        $token = $this->createApiToken($user);
        $response = $this->apiGet('/api/reports/todos', $token)->assertOk();
        $this->assertSame(2, $response->json('pending'));
        $assertByUnit($response->json('by_unit'));

        $advanced = Livewire::test('reports.advanced')->set('reportType', 'todos')->instance()->reportData();
        $this->assertSame(2, $advanced['total']);
        $assertByUnit($advanced['byUnit']);

        $payload = Livewire::test('reports.todos')->instance()->chartPayload();
        $this->assertSame(2, $payload['pending']);
        $assertByUnit($payload['byUnit']);
    }
}
