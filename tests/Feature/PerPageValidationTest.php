<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #894 — every paginated API endpoint capped `per_page` from ABOVE only.
 *
 * `min((int) $per_page, 100)` bounds the upper side and nothing else. A negative
 * value is truthy, so it passes `Eloquent\Builder::paginate()`'s
 * `?: $this->model->getPerPage()` fallback, reaches `forPage()`, and
 * `limit(-1)` is dropped by the query builder — the emitted SQL loses its LIMIT
 * clause entirely and returns the whole scoped table.
 *
 * The contract is **422, not a silent clamp**, matching the house pattern in
 * `ReportController::days()` + `app/Rules/ReportDays.php`. The existing
 * `min(...)` expressions stay as defence in depth.
 *
 * These live in their own file rather than the per-controller API tests so one
 * place documents the shared rule; the two named existing tests
 * (`UnitApiTest::test_pagination_per_page_is_limited`,
 * `HardwareAuditDetailTest::test_index_respects_max_per_page`) are extended
 * separately with a row-count assertion, which is the weakness they had.
 */
class PerPageValidationTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Every endpoint that reads `per_page`, with the permission + token ability
     * its route requires and the cap it enforces.
     *
     * `HardwareAuditController` is exercised separately because its route is
     * nested under a hardware row.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function endpointProvider(): array
    {
        return [
            'units' => ['/api/units', 'organization', ['units:read']],
            'hardware' => ['/api/hardware', 'manage_hardware', ['hardware:read']],
            'todos' => ['/api/todos', 'calendar', ['todos:read']],
            'persons' => ['/api/persons', 'kargozini', ['persons:read']],
            'hr personnel' => ['/api/hr/personnel', 'view_hr_dashboard', ['hr:read']],
        ];
    }

    /**
     * A negative `per_page` must be refused with 422, not silently answer with
     * the whole scoped table.
     */
    #[DataProvider('endpointProvider')]
    public function test_negative_per_page_is_rejected(string $endpoint, string $permission, array $abilities): void
    {
        $this->createUserWithUnit([$permission]);
        $token = $this->createApiToken(User::first(), $abilities);

        $response = $this->apiGet("{$endpoint}?per_page=-1", $token);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
    }

    /**
     * `per_page=0` is out of the same 1..MAX contract. Before the fix it was a
     * silent non-event (the model default of 15 won via `?:`); now the client
     * is told.
     */
    #[DataProvider('endpointProvider')]
    public function test_zero_per_page_is_rejected(string $endpoint, string $permission, array $abilities): void
    {
        $this->createUserWithUnit([$permission]);
        $token = $this->createApiToken(User::first(), $abilities);

        $this->apiGet("{$endpoint}?per_page=0", $token)->assertStatus(422);
    }

    /**
     * Above the cap is 422 too — the 422 contract replaces the silent clamp,
     * it does not merely add a lower bound.
     */
    #[DataProvider('endpointProvider')]
    public function test_per_page_above_the_cap_is_rejected(string $endpoint, string $permission, array $abilities): void
    {
        $this->createUserWithUnit([$permission]);
        $token = $this->createApiToken(User::first(), $abilities);

        $this->apiGet("{$endpoint}?per_page=1000", $token)->assertStatus(422);
    }

    /**
     * The regression the expert review flagged: junk input must keep falling
     * back to the controller's default, exactly like `?days=abc` does. A naive
     * `max(1, min(...))` would answer 1 row here instead of the default page.
     */
    #[DataProvider('endpointProvider')]
    public function test_non_numeric_per_page_falls_back_to_the_default(string $endpoint, string $permission, array $abilities): void
    {
        $this->createUserWithUnit([$permission]);
        $token = $this->createApiToken(User::first(), $abilities);

        $response = $this->apiGet("{$endpoint}?per_page=abc", $token);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.per_page'));
    }

    /**
     * The value that used to break it: `-1` returned the entire scoped table
     * with `meta.per_page = -1`. Pin the row count, not just the status — the
     * original tests asserted neither, which is how the defect survived.
     */
    public function test_negative_per_page_returns_no_rows_rather_than_the_whole_table(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['organization']);

        for ($i = 0; $i < 30; $i++) {
            Unit::create(['name' => "Unit {$i}", 'parent_id' => $unit->id]);
        }

        $token = $this->createApiToken(User::first(), ['units:read']);

        $this->apiGet('/api/units?per_page=-1', $token)
            ->assertStatus(422);

        // And a legitimate page is still bounded.
        $ok = $this->apiGet('/api/units?per_page=5', $token);
        $ok->assertStatus(200);
        $this->assertCount(5, $ok->json('data'));
    }

    /**
     * `initial_limit` carries the same defect and is the more dangerous site:
     * it calls `->limit()` directly, and the value is hashed into the cache key,
     * so each distinct negative minted a fresh full-scope entry.
     */
    public function test_negative_initial_limit_is_rejected(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);

        // Inside the caller's scope, so a `limit(-1)` really would return more
        // rows than the cap allows — the tree is rooted at reachable units.
        for ($i = 0; $i < 10; $i++) {
            Unit::create(['name' => "Child {$i}", 'parent_id' => $unit->id]);
        }

        $token = $this->createApiToken(User::first(), ['hr:read']);

        $this->apiGet('/api/hr/org-chart/expandable?initial_limit=-1', $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['initial_limit']);
    }

    public function test_valid_initial_limit_is_unchanged(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);

        Unit::create(['name' => 'واحد ۲', 'parent_id' => $unit->id]);
        Unit::create(['name' => 'واحد ۳', 'parent_id' => $unit->id]);

        $token = $this->createApiToken(User::first(), ['hr:read']);

        $response = $this->apiGet('/api/hr/org-chart/expandable?initial_limit=2', $token);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.initial_limit'));
    }

    /**
     * `HardwareAuditController` caps at 50, so it carries its own rule instance.
     */
    public function test_hardware_audits_reject_an_out_of_range_per_page(): void
    {
        $result = $this->createUserWithUnit(['manage_hardware']);

        // Hardware is reached through `persons.u_id` by its `n_code`, so the
        // record needs the creator's n_code to land inside the caller's scope.
        $person = Person::where('n_code', $result['user']->n_code)->firstOrFail();

        $hardware = Hardware::factory()->create(['n_code' => $person->n_code]);
        $token = $this->createApiToken($result['user'], ['hardware:read']);

        $this->apiGet("/api/hardware/{$hardware->id}/audits?per_page=-1", $token)
            ->assertStatus(422);

        // 51 is inside the rule but above this endpoint's own cap of 50.
        $this->apiGet("/api/hardware/{$hardware->id}/audits?per_page=51", $token)
            ->assertStatus(422);

        $this->apiGet("/api/hardware/{$hardware->id}/audits?per_page=10000", $token)
            ->assertStatus(422);

        $this->apiGet("/api/hardware/{$hardware->id}/audits?per_page=50", $token)
            ->assertStatus(200);
    }
}
