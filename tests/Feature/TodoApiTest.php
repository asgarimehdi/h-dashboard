<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TodoController;
use App\Models\Todo;
use App\Models\Unit;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(TodoController::class);

class TodoApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_unauthenticated_user_cannot_access_todos(): void
    {
        $response = $this->getJson('/api/todos');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_list_todos(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        Todo::factory()->count(3)->create(['unit_id' => $unit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet('/api/todos', $token);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'start_at', 'end_at', 'is_completed', 'unit_id'],
                ],
            ]);
    }

    public function test_todo_without_unit_id_falls_back_to_the_primary_unit(): void
    {
        // #1803: the fallback chain is `unit_id -> primaryUnit() -> person.u_id`.
        // With exactly one primary enforced, primaryUnit() is deterministic —
        // pin that a todo with no unit_id lands in the pivot primary, not the
        // person's home unit.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $primaryUnit = Unit::create(['name' => 'واحد اصلی', 'parent_id' => $unit->id]);
        $user->setPrimaryUnit($primaryUnit->id);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'تسک بدون واحد',
            'start_at' => '2026-07-15 10:00:00',
        ], $token);

        $response->assertStatus(201);

        $this->assertDatabaseHas('todos', [
            'title' => 'تسک بدون واحد',
            'unit_id' => $primaryUnit->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_create_todo_in_accessible_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'تست تسک جدید',
            'start_at' => '2026-07-15 10:00:00',
            'end_at' => '2026-07-20 10:00:00',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('todos', [
            'title' => 'تست تسک جدید',
            'unit_id' => $unit->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_created_todo_belongs_to_authenticated_user(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'Ownership Test',
            'start_at' => '2026-07-15 10:00:00',
        ], $token);

        $response->assertStatus(201);
        $this->assertDatabaseHas('todos', [
            'title' => 'Ownership Test',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_create_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'Unauthorized Todo',
            'start_at' => '2026-07-15 10:00:00',
            'unit_id' => $inaccessibleUnit->id,
        ], $token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized to create todo in this unit.']);
    }

    public function test_user_can_update_own_todo(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'Old Title',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'title' => 'Updated Title',
            'start_at' => '2026-07-16 10:00:00',
        ], $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'title' => 'Updated Title',
                ],
            ]);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'title' => 'Updated Title',
        ]);
    }

    /**
     * #1659: `after_or_equal:start_at` only sees submitted fields, so an
     * update that submits one side of the range never compares against the
     * stored counterpart. These lock the effective-range check on the API.
     */
    public function test_update_only_start_after_stored_end_is_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'start_at' => '2026-07-03 10:00:00',
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'The end date must be after or equal to the start date.']);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
    }

    public function test_update_only_end_before_stored_start_is_rejected(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'end_at' => '2026-06-30 10:00:00',
        ], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'The end date must be after or equal to the start date.']);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
    }

    public function test_update_start_equal_to_stored_end_is_accepted(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'start_at' => '2026-07-02 10:00:00',
        ], $token);

        $response->assertStatus(200);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-02 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
    }

    public function test_update_with_stored_null_end_accepts_a_later_start(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => null,
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'start_at' => '2026-07-05 10:00:00',
        ], $token);

        $response->assertStatus(200);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-05 10:00:00',
            'end_at' => null,
        ]);
    }

    public function test_update_can_clear_end_at_with_explicit_null(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'end_at' => null,
        ], $token);

        $response->assertStatus(200);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => null,
        ]);
    }

    public function test_update_with_a_valid_range_still_succeeds(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'بازه',
            'start_at' => '2026-07-01 10:00:00',
            'end_at' => '2026-07-02 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'start_at' => '2026-07-03 10:00:00',
            'end_at' => '2026-07-04 10:00:00',
        ], $token);

        $response->assertStatus(200);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'start_at' => '2026-07-03 10:00:00',
            'end_at' => '2026-07-04 10:00:00',
        ]);
    }

    public function test_title_only_update_of_a_legacy_inverted_row_still_succeeds(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'قدیمی',
            'start_at' => '2026-07-02 10:00:00',
            'end_at' => '2026-07-01 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'title' => 'عنوان جدید',
        ], $token);

        $response->assertStatus(200);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'title' => 'عنوان جدید',
            'start_at' => '2026-07-02 10:00:00',
            'end_at' => '2026-07-01 10:00:00',
        ]);
    }

    public function test_user_can_delete_own_todo(): void
    {

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiDelete("/api/todos/{$todo->id}", $token);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    }

    public function test_user_can_toggle_todo_completion(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);
        $this->assertFalse($todo->is_completed);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_completed' => true,
                ],
            ]);

        $this->assertTrue((bool) $todo->fresh()->is_completed);
    }

    // =====================================================================
    // #1634 — toggleComplete is atomic; a toggle is never silently lost
    // =====================================================================

    /**
     * Make the toggle's `FOR UPDATE` probe observe a stale snapshot.
     *
     * Same mechanism as `TicketApiTest::fakeLockedTicketRow()` (#1608): the
     * facade is swapped for a `DatabaseManager` subclass that delegates
     * `connection()` to the real manager (a fresh manager would open a second
     * PDO outside RefreshDatabase's transaction and see no rows at all) and
     * answers only the `FOR UPDATE` probe with the given row.
     *
     * @param  object|null  $row  the row the lock reports, or null for "gone"
     */
    protected function fakeLockedTodoRow(?object $row): void
    {
        /** @var DatabaseManager $inner */
        $inner = DB::getFacadeRoot();

        DB::swap(new class(app(), app('db.factory'), $inner, $row) extends DatabaseManager
        {
            public function __construct($app, $factory, private DatabaseManager $inner, private ?object $row)
            {
                parent::__construct($app, $factory);
            }

            public function connection($name = null)
            {
                return $this->inner->connection($name);
            }

            public function select($query, $bindings = [], $useReadPdo = true)
            {
                if (str_contains($query, 'FOR UPDATE')) {
                    return $this->row === null ? [] : [$this->row];
                }

                return $this->inner->connection()->select($query, $bindings, $useReadPdo);
            }
        });
    }

    public function test_toggle_complete_uses_the_locked_row_not_the_stale_model(): void
    {
        // The stale in-memory model says `false`, but another writer already
        // flipped the row to `true` before our lock. Deriving the new value from
        // the stale model writes `true` again and silently loses our toggle; the
        // locked read must be the source of truth.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);
        $this->assertFalse($todo->is_completed);

        $this->fakeLockedTodoRow((object) array_merge(
            $todo->getAttributes(),
            ['is_completed' => true],
        ));

        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);
        $response = $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_completed' => false,
                ],
            ]);

        $this->assertFalse((bool) $todo->fresh()->is_completed);
    }

    public function test_toggle_complete_returns_422_when_row_vanished_under_lock(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);

        $this->fakeLockedTodoRow(null);

        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);
        $response = $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Todo no longer exists.']);

        $this->assertDatabaseHas('todos', ['id' => $todo->id, 'is_completed' => false]);
    }

    public function test_double_toggle_returns_to_the_starting_value(): void
    {
        // Sanity only: two sequential flips also return to the starting value on
        // the buggy read-then-write code, so this is NOT the race guard — the
        // locked-row test above is.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);

        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token)->assertStatus(200);
        $this->assertTrue((bool) $todo->fresh()->is_completed);

        $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token)->assertStatus(200);
        $this->assertFalse((bool) $todo->fresh()->is_completed);
    }

    public function test_toggle_complete_still_invalidates_report_and_dashboard_caches(): void
    {
        // The write must go through the model: a query-builder mass update fires
        // no `Todo::updated`, so report_todos + dashboard would silently stop
        // invalidating (AppServiceProvider).
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create([
            'unit_id' => $unit->id,
            'is_completed' => false,
        ]);

        $cache = app(CacheInvalidationServiceInterface::class);
        $reportVersion = $cache->getVersion('report_todos');
        $dashboardVersion = $cache->getVersion('dashboard');

        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);
        $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token)->assertStatus(200);

        $this->assertGreaterThan($reportVersion, $cache->getVersion('report_todos'));
        $this->assertGreaterThan($dashboardVersion, $cache->getVersion('dashboard'));
    }

    public function test_todo_list_respects_jalali_date_filtering(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-20 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet('/api/todos?date=2026-07-15', $token);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // --- Issue #1750: query-parameter validation on GET /api/todos ---
    // Before this fix `date`, `month` and `year` were bound straight into
    // whereDate/whereMonth/whereYear: garbage `date`/`year` reached Postgres as
    // a raw literal and 500'd, while garbage `month` was silently zero-padded
    // to `00` by the query builder (200 with 0 rows).

    public function test_todo_list_rejects_garbage_date(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read']);

        foreach (['date=xyz', 'date=2026-02-30', 'date[]=xyz'] as $query) {
            $this->apiGet("/api/todos?{$query}", $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors('date');
        }
    }

    public function test_todo_list_rejects_garbage_month_year(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read']);

        // Garbage alone is a 422 even though a well-formed lone `month`/`year`
        // stays a 200 no-op (see test_todo_list_ignores_half_supplied_month_year).
        $cases = [
            'month=abc' => 'month',
            'month=abc&year=2026' => 'month',
            'month=13&year=2026' => 'month',
            'month=0&year=2026' => 'month',
            'year=abc&month=1' => 'year',
            'year=99999&month=1' => 'year',
        ];

        foreach ($cases as $query => $field) {
            $this->apiGet("/api/todos?{$query}", $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_todo_list_keeps_valid_date_filters(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $matching = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-20 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // The last two were already 200 before the fix; they must keep matching
        // the same row so the added rules do not narrow the accepted formats.
        foreach (['2026-07-15', '2026-07-15 10:00:00', '2026-07-15T10:00:00Z'] as $date) {
            $response = $this->apiGet('/api/todos?date='.urlencode($date), $token);

            $response->assertStatus(200);
            $this->assertSame([$matching->id], array_column($response->json('data'), 'id'), "date={$date}");
        }
    }

    public function test_todo_list_normalizes_date_postgres_would_reject(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $matching = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // `26-07-15` passes Laravel's `date` rule but Postgres rejects the raw
        // literal, so it 500'd before; Carbon normalization makes it a valid
        // Y-m-d filter instead.
        $response = $this->apiGet('/api/todos?date='.urlencode('26-07-15'), $token);

        $response->assertStatus(200);
        $this->assertSame([$matching->id], array_column($response->json('data'), 'id'));
    }

    public function test_todo_list_does_not_narrow_accepted_date_formats(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // Both were 200 with no rows before the fix; the rules must not start
        // rejecting formats the endpoint already accepted.
        $this->apiGet('/api/todos?date='.urlencode('2026-7-5'), $token)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->apiGet('/api/todos?date='.urlencode('1405/08/02'), $token)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_todo_list_ignores_half_supplied_month_year(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $july = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        $august = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-08-15 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // Unchanged contract: a well-formed lone `month` or `year` applies no
        // filter at all (the pair is required), so every row is returned.
        foreach (['month=8', 'year=2026'] as $query) {
            $response = $this->apiGet("/api/todos?{$query}", $token);

            $response->assertStatus(200);
            $ids = array_column($response->json('data'), 'id');
            sort($ids);
            $this->assertSame([$july->id, $august->id], $ids, $query);
        }
    }

    public function test_todo_list_accepts_zero_padded_month(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $matching = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-08-15 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // `integer` rejects '08' (FILTER_VALIDATE_INT), which would regress a
        // request that works today — the rules must stay numeric-based.
        $response = $this->apiGet('/api/todos?month=08&year=2026', $token);

        $response->assertStatus(200);
        $this->assertSame([$matching->id], array_column($response->json('data'), 'id'));
    }

    public function test_todo_list_treats_empty_date_as_noop(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $first = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-15 10:00:00',
        ]);
        $second = Todo::factory()->create([
            'unit_id' => $unit->id,
            'start_at' => '2026-07-20 10:00:00',
        ]);
        $token = $this->createApiToken($user, ['todos:read']);

        // ConvertEmptyStringsToNull turns `?date=` into null; without a
        // `nullable` rule this becomes a 422 where it is a 200 no-op today.
        $response = $this->apiGet('/api/todos?date=', $token);

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_todo_list_filters_by_is_completed(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => true]);
        Todo::factory()->create(['unit_id' => $unit->id, 'is_completed' => false]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet('/api/todos?is_completed=true', $token);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_user_can_view_todo_in_accessible_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => $unit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet("/api/todos/{$todo->id}", $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $todo->id,
                ],
            ]);
    }

    public function test_user_cannot_view_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet("/api/todos/{$todo->id}", $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_update_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'title' => 'Hacked Title',
        ], $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create(['unit_id' => $inaccessibleUnit->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiDelete("/api/todos/{$todo->id}", $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_todo_in_inaccessible_unit(): void
    {
        ['user' => $user, 'unit' => $accessibleUnit] = $this->createUserWithUnit(['calendar']);
        $inaccessibleUnit = Unit::factory()->create(['name' => 'Inaccessible Unit']);

        $todo = Todo::factory()->create([
            'unit_id' => $inaccessibleUnit->id,
            'is_completed' => false,
        ]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token);

        $response->assertStatus(403);
    }

    public function test_todo_with_null_unit_is_denied(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);

        $todo = Todo::factory()->create(['unit_id' => null]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        // Null-unit todos are outside any user's org scope — must be denied (issue #249)
        $response = $this->apiGet("/api/todos/{$todo->id}", $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_update_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'title' => 'Hacked Title',
        ], $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiDelete("/api/todos/{$todo->id}", $token);

        $response->assertStatus(403);
    }

    public function test_user_cannot_toggle_todo_with_null_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null, 'is_completed' => false]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token);

        $response->assertStatus(403);
    }

    public function test_creator_can_view_own_null_unit_todo(): void
    {
        // Null-unit contract (#838): a null-unit todo belongs to its creator —
        // the API and the Livewire UI enforce the same rule row-for-row.
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null, 'user_id' => $user->id]);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet("/api/todos/{$todo->id}", $token);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $todo->id,
                ],
            ]);
    }

    public function test_non_creator_cannot_view_null_unit_todo(): void
    {
        ['user' => $creator] = $this->createUserWithUnit(['calendar']);
        ['user' => $other] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null, 'user_id' => $creator->id]);
        $token = $this->createApiToken($other, ['todos:read', 'todos:write']);

        $response = $this->apiGet("/api/todos/{$todo->id}", $token);

        $response->assertStatus(403);
    }

    public function test_index_lists_creator_own_null_unit_todo(): void
    {
        // Null-unit contract (#917): index() must apply the same grouped
        // predicate as show()/getEvents() — the creator's own null-unit todo
        // belongs in the list, serialized with "unit": null.
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => null, 'user_id' => $user->id, 'title' => 'OWN-NULL-UNIT-917']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiGet('/api/todos', $token);

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $todo->id, 'title' => 'OWN-NULL-UNIT-917', 'unit_id' => null, 'unit' => null]);
    }

    public function test_index_hides_foreign_null_unit_todo(): void
    {
        // Negative control: another user's null-unit todo stays invisible.
        ['user' => $creator] = $this->createUserWithUnit(['calendar']);
        ['user' => $other] = $this->createUserWithUnit(['calendar']);
        Todo::factory()->create(['unit_id' => null, 'user_id' => $creator->id, 'title' => 'FOREIGN-NULL-UNIT-917']);
        $token = $this->createApiToken($other, ['todos:read', 'todos:write']);

        $response = $this->apiGet('/api/todos', $token);

        $response->assertStatus(200)
            ->assertJsonMissing(['title' => 'FOREIGN-NULL-UNIT-917']);
    }

    public function test_my_work_surfaces_list_own_null_unit_todo(): void
    {
        // Profile counters/list and global search are "my work" surfaces —
        // they must show the viewer's own null-unit todo too.
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        Todo::factory()->create(['unit_id' => null, 'user_id' => $user->id, 'title' => 'MYWORK-NULL-917']);
        $this->actingAs($user);

        Livewire::test('profile.index')
            ->assertSet('totalTodos', 1)
            ->assertSee('MYWORK-NULL-917');

        $results = Livewire::test('search.index')
            ->set('query', 'MYWORK-NULL-917')
            ->call('search')
            ->get('results');

        $this->assertContains(
            'MYWORK-NULL-917',
            collect($results['todos'])->pluck('title')->all(),
            'global search shows the viewer\'s own null-unit todo'
        );
    }

    public function test_ticket_picker_offers_own_null_unit_todo(): void
    {
        // The ticket picker disagreed with AccessibleTodo: the rule accepts
        // the creator's own null-unit todo while the picker hid it.
        ['user' => $user] = $this->createUserWithUnit(['calendar', 'create_ticket']);
        $todo = Todo::factory()->create(['unit_id' => null, 'user_id' => $user->id, 'is_completed' => false]);

        $todos = Livewire::actingAs($user)->test('tickets.create')->get('todos');

        $this->assertContains(
            $todo->id,
            collect($todos)->pluck('id')->all(),
            'ticket picker offers the creator\'s own null-unit todo'
        );
    }

    public function test_todo_with_null_unit_not_created_via_store(): void
    {
        // Regression for issue #249: even when unit_id is omitted entirely,
        // the controller must fall back to person->u_id and never store a null-unit todo.
        // Here the user HAS a person with a unit, so this succeeds — proving the
        // fallback path still works. The null-bypass is covered by the show/update/
        // delete/toggle null-unit tests above (all expect 403).
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'Fallback Todo',
            'start_at' => '2026-07-15 10:00:00',
        ], $token);

        $response->assertStatus(201)
            ->assertJsonPath('data.unit_id', $unit->id);
    }

    public function test_user_can_create_todo_without_unit_id_using_person_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => 'Person Unit Todo',
            'start_at' => '2026-07-15 10:00:00',
        ], $token);

        $response->assertStatus(201);
        // Should have used the person's unit automatically
        $this->assertEquals($unit->id, $response->json('data')['unit_id'], 'falls back to person.u_id when no unit_id provided');
    }

    public function test_delete_non_existent_todo_returns_404(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        // Try to delete a non-existent todo (ID 99999)
        $response = $this->apiDelete('/api/todos/99999', $token);

        $response->assertStatus(404);
    }

    /**
     * #1658: `title` had no upper bound on either API write path, so an
     * over-long title hit the varchar(255) column and produced a 500. The cap
     * is exactly the column width.
     */
    public function test_store_rejects_title_above_max_length(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPost('/api/todos', [
            'title' => str_repeat('ا', 256),
            'start_at' => '2026-07-15 10:00:00',
            'unit_id' => $unit->id,
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertDatabaseCount('todos', 0);
    }

    public function test_update_rejects_title_above_max_length_and_keeps_old(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $todo = Todo::factory()->create(['unit_id' => $unit->id, 'title' => 'Old']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);

        $response = $this->apiPut("/api/todos/{$todo->id}", [
            'title' => str_repeat('ا', 256),
        ], $token);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertDatabaseHas('todos', ['id' => $todo->id, 'title' => 'Old']);
    }

    public function test_store_accepts_title_at_exactly_max_length(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['calendar']);
        $token = $this->createApiToken($user, ['todos:read', 'todos:write']);
        $title = str_repeat('ا', 255);

        $response = $this->apiPost('/api/todos', [
            'title' => $title,
            'start_at' => '2026-07-15 10:00:00',
            'unit_id' => $unit->id,
        ], $token);

        // 255 Persian characters = 510 bytes; proves the cap matches the
        // varchar(255) column without a 500.
        $response->assertStatus(201);

        $this->assertDatabaseHas('todos', ['title' => $title]);
    }
}
