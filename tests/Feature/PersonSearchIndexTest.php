<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1009 — the personnel full-name search must build the concatenation
 * with the `||` operator so PostgreSQL can match the two expression trigram
 * indexes created by migration `2026_08_22_000001`.
 *
 * `concat()` is STABLE (not IMMUTABLE), so the planner can never fold
 * `((f_name || ' ' || l_name))` and `CONCAT(f_name, ' ', l_name)` into the same
 * expression tree — every keystroke sequential-scanned `persons`. The three
 * unfolded call sites are swapped to `||`; the folded sites (wrapped in
 * `foldSeparatorsSql()`) stay as they are because no index can serve them.
 *
 * The swap is only safe because `f_name` and `l_name` are NOT NULL: `||` is
 * NULL-propagating, while `concat()` treats NULL as an empty string. Both the
 * behaviour (identical results for non-NULL names) and the schema guard are
 * pinned below.
 */
class PersonSearchIndexTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** A deterministic 10-char national code for the person under test. */
    private const N_CODE = '9000000001';

    private const F_NAME = 'علی';

    private const L_NAME = 'رضایی';

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create the deterministic person inside the given unit.
     */
    private function createAliRezaei(int $unitId): Person
    {
        return Person::create([
            'n_code' => self::N_CODE,
            'f_name' => self::F_NAME,
            'l_name' => self::L_NAME,
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unitId,
        ]);
    }

    /**
     * Capture every SQL statement run while the callback executes.
     *
     * @return list<string>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    /**
     * The person full-name search query is the one that matches `f_name` with a
     * LIKE. Assert it is built with the exact `||` expression (index-eligible)
     * and no longer with the `concat()` function (STABLE → index-blind).
     *
     * `$expectedExpression` is the exact, site-specific index expression — a
     * bare `||` check would pass vacuously for any unrelated `||` later added
     * to the query.
     *
     * @param  list<string>  $queries
     */
    private function assertFullNameSearchUsesOperator(array $queries, string $label, string $expectedExpression): void
    {
        $sql = null;

        foreach ($queries as $query) {
            if (str_contains($query, 'f_name') && str_contains($query, 'LIKE')) {
                $sql = $query;
                break;
            }
        }

        $this->assertNotNull($sql, "{$label}: the full-name search query must run");
        $this->assertStringContainsString(
            $expectedExpression,
            $sql,
            "{$label}: the full-name search must build {$expectedExpression} so the trigram index applies"
        );
        $this->assertStringNotContainsString('concat(', strtolower($sql), "{$label}: concat() is STABLE and can never match the expression index");
    }

    // ==================== Behaviour (unchanged by the operator swap) ====================

    public function test_full_name_search_matches_across_the_name_boundary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->createAliRezaei($unit->id);
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet('/api/persons?search='.urlencode(self::F_NAME.' '.self::L_NAME), $token);

        $response->assertStatus(200);
        $this->assertContains(self::N_CODE, array_column($response->json('data'), 'n_code'));
    }

    public function test_surname_only_search_still_matches(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->createAliRezaei($unit->id);
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet('/api/persons?search='.urlencode(self::L_NAME), $token);

        $response->assertStatus(200);
        $this->assertContains(self::N_CODE, array_column($response->json('data'), 'n_code'));
    }

    public function test_no_match_returns_an_empty_page_without_error(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_personnel']);
        $token = $this->createApiToken($user, ['persons:read']);

        $response = $this->apiGet('/api/persons?search='.urlencode('نامناموجود12345'), $token);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    // ==================== Tripwires: the three unfolded sites ====================

    public function test_person_api_search_uses_the_pipe_operator(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_personnel']);
        $this->createAliRezaei($unit->id);
        $token = $this->createApiToken($user, ['persons:read']);

        $queries = $this->captureQueries(function () use ($token): void {
            $this->apiGet('/api/persons?search='.urlencode(self::F_NAME), $token)->assertStatus(200);
        });

        $this->assertFullNameSearchUsesOperator($queries, 'PersonController::index()', "(f_name || ' ' || l_name) LIKE");
    }

    public function test_hr_personnel_search_uses_the_pipe_operator(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_hr_dashboard']);
        $this->createAliRezaei($unit->id);
        $token = $this->createApiToken($user, ['hr:read']);

        $queries = $this->captureQueries(function () use ($token): void {
            $this->apiGet('/api/hr/personnel?search='.urlencode(self::F_NAME), $token)->assertStatus(200);
        });

        $this->assertFullNameSearchUsesOperator($queries, 'HrStatsController::personnel()', "(persons.f_name || ' ' || persons.l_name) LIKE");
    }

    public function test_hardware_person_autocomplete_uses_the_pipe_operator(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $queries = $this->captureQueries(function (): void {
            Livewire::test('hardware.index')->set('personSearch', self::F_NAME);
        });

        $this->assertFullNameSearchUsesOperator($queries, 'hardware.index person autocomplete', "(f_name || ' ' || l_name) LIKE");
    }

    // ==================== Schema guard ====================

    public function test_full_name_columns_are_not_nullable(): void
    {
        foreach (['f_name', 'l_name'] as $column) {
            $row = DB::selectOne(
                'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
                ['persons', $column]
            );

            $this->assertNotNull($row, "persons.{$column} must exist");
            $this->assertSame(
                'NO',
                $row->is_nullable,
                "persons.{$column} must stay NOT NULL: the || operator is NULL-propagating, unlike concat()"
            );
        }
    }
}
