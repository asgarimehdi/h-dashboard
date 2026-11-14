<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PersonsExportController;
use App\Http\Requests\UnitScopedRequest;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Support\HardwareFilters;
use App\Traits\PersianNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1482 — every Persian search site folded the COLUMN at query time
 * (`foldSeparatorsSql()`), which wraps the column in `regexp_replace()`: no
 * trigram index can ever match that expression, and PostgreSQL GIN rechecks
 * the whole fold per candidate row.
 *
 * The fix is a stored generated column per searched text column, indexed with
 * a plain `gin_trgm_ops` GIN index. These pins are the reason the columns can
 * be trusted:
 *
 *   - the catalog definition must stay the helper's expression (drift guard);
 *   - a RAW insert with no Eloquent hook must still get the folded value;
 *   - the folded columns must return EXACTLY the rows the old predicate did;
 *   - the planner must actually choose the folded trigram indexes;
 *   - the SQL fold and `normalizeForSearch()` must agree on the same matrix.
 *
 * Units (834 rows) and semats (56 rows) are expected to keep a sequential
 * scan — the fold is only about not re-evaluating the regex per row there.
 */
class FoldedColumnsTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Persons carry FKs to the four lookup tables; the raw-insert pin needs
        // the deterministic id=1 rows those helpers seed.
        $this->seedLookupTables();
    }

    /**
     * Generated column => the base expression expected to be folded into it.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function foldedColumnProvider(): array
    {
        return [
            'persons.f_name_folded' => ['persons', 'f_name_folded', 'f_name'],
            'persons.l_name_folded' => ['persons', 'l_name_folded', 'l_name'],
            'persons.full_name_folded' => ['persons', 'full_name_folded', "(f_name || ' ' || l_name)"],
            'persons.full_name_rev_folded' => ['persons', 'full_name_rev_folded', "(l_name || ' ' || f_name)"],
            'units.name_folded' => ['units', 'name_folded', 'name'],
            'semats.name_folded' => ['semats', 'name_folded', 'name'],
        ];
    }

    /**
     * Force the planner past a sequential scan: on the handful of rows a test
     * seeds, PostgreSQL prefers a seq scan no matter which indexes exist, so
     * `enable_seqscan = off` is what makes index usage observable.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, string> the plan lines
     */
    private function planFor(string $sql, array $bindings = []): array
    {
        DB::statement('SET LOCAL enable_seqscan = off');

        return array_map(
            'strval',
            array_column(DB::select('EXPLAIN '.$sql, $bindings), 'QUERY PLAN')
        );
    }

    /**
     * Same, but also forbid plain index scans: a semi-join on
     * `persons.n_code` has a unique B-tree shortcut, so on a small fixture the
     * planner reads persons through that index and filters the OR row by row.
     * With both seq scan and plain index scan off, the only way to satisfy the
     * OR is a bitmap OR — which is exactly the indexability this test pins.
     *
     * @param  array<int, mixed>  $bindings
     * @return array<int, string> the plan lines
     */
    private function planForOr(string $sql, array $bindings = []): array
    {
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL enable_indexscan = off');

        return array_map(
            'strval',
            array_column(DB::select('EXPLAIN '.$sql, $bindings), 'QUERY PLAN')
        );
    }

    /**
     * The trait's helpers are static and are meant to be consumed by a class
     * that `use`s the trait — calling them on the trait itself is deprecated in
     * PHP 8.1+ — so the test reads them through a throwaway consumer.
     */
    private function normalizer(): object
    {
        return new class
        {
            use PersianNormalizer;
        };
    }

    /**
     * A person row inserted WITHOUT the model, so no `saving` hook runs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertRawPerson(string $nCode, string $fName, string $lName, ?int $unitId = null): void
    {
        DB::table('persons')->insert([
            'n_code' => $nCode,
            'f_name' => $fName,
            'l_name' => $lName,
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ==================== 1. Catalog parity / drift guard ====================

    /**
     * The column definition must be the helper's expression, character for
     * character. Comparing against `foldSeparatorsSql()` (rather than checking
     * for a `regexp_replace` token) is what makes this a lockstep guard:
     * change `charMap()`/`digitMap()` without recreating the column and this
     * fails, catching a silently-stale folded value before it reaches
     * production — the same guard `TicketsTodosTrgmIndexTest` applies to the
     * ticket expression indexes.
     *
     * PostgreSQL prints the stored expression with `::text` casts and wraps
     * column references in parentheses, so both sides drop casts and parens —
     * neither is part of the folding logic.
     */
    #[DataProvider('foldedColumnProvider')]
    public function test_the_generated_column_mirrors_the_helper(string $table, string $column, string $source): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        $row = DB::selectOne(
            'SELECT a.attgenerated AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE c.relname = ? AND a.attname = ?',
            [$table, $column]
        );

        $this->assertNotNull($row, "{$table}.{$column} must exist");
        $this->assertSame(
            's',
            $row->generated,
            "{$table}.{$column} must be GENERATED ALWAYS AS (...) STORED so PostgreSQL maintains it on every write path",
        );

        $normalise = static fn (string $sql): string => str_replace(['::text', '(', ')'], '', $sql);

        $this->assertStringContainsString(
            $normalise($this->normalizer()->foldSeparatorsSql($source)),
            $normalise($row->expression),
            "{$table}.{$column} must be generated from PersianNormalizer::foldSeparatorsSql('{$source}'), not hand-copied SQL",
        );
    }

    // ==================== 2. Hook-bypass write ====================

    /**
     * `Person` has a `saving` hook, `Unit`/`Semat` have none, and a raw
     * `DB::table()->insert()` bypasses Eloquent entirely — but the stored
     * column is maintained by PostgreSQL itself, whatever wrote the row.
     *
     * This is the pin against "just normalize in the saving hook": a stale
     * folded column would be a FALSE NEGATIVE (a row that exists but never
     * matches), which is worse than a slow query.
     */
    public function test_a_raw_insert_gets_the_folded_columns_without_any_model_hook(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        // Arabic Yeh (U+064A) + ZWNJ in the surname — exactly the input a
        // Persian keyboard, a legacy export or an API client can produce.
        $this->insertRawPerson('1234567890', 'علي', "رضائي\u{200C}پور");

        $row = DB::table('persons')->where('n_code', '1234567890')->first();

        $this->assertSame('علی', $row->f_name_folded);
        $this->assertSame('رضائی پور', $row->l_name_folded);
        $this->assertSame('علی رضائی پور', $row->full_name_folded);
        $this->assertSame('رضائی پور علی', $row->full_name_rev_folded);
    }

    // ==================== 3. Result parity ====================

    /**
     * The behavioural gate a plan-shape test cannot give: on the same fixture,
     * the old per-row fold and the new stored columns must select the identical
     * id set for every tricky spelling.
     */
    public function test_the_folded_columns_return_the_same_rows_as_the_old_predicate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        $unit = Unit::factory()->create(['name' => 'مرکز آزمون']);
        $fixtures = [
            ['علي', "رضائي\u{200C}پور"],   // Arabic Yeh + ZWNJ
            ['رضائی', 'پور'],
            ['أحمد', 'حسین  آباد'],         // Arabic Alef with Hamza + double space
            ['۱۲۳', 'تست'],                 // Persian digits
            ['علی', 'رضایی'],
            ['محمد', 'رضا'],
        ];

        foreach ($fixtures as $index => [$fName, $lName]) {
            $this->insertRawPerson((string) (2000000000 + $index), $fName, $lName, $unit->id);
        }

        $normalizer = $this->normalizer();

        $terms = [
            'علي',            // Arabic Yeh typed, Persian Yeh stored
            'علی',
            'رضائي پور',      // ZWNJ broken into a space
            'رضائی پور',
            'أحمد',
            'احمد',
            '۱۲۳',
            '123',
            'حسین آباد',      // stored with a double space
            'رضایی',
        ];

        foreach ($terms as $term) {
            $folded = $normalizer->foldedTerm($term);

            $old = DB::table('persons')
                ->whereRaw($normalizer->foldSeparatorsSql('f_name').' LIKE ?', ["%{$folded}%"])
                ->orWhereRaw($normalizer->foldSeparatorsSql('l_name').' LIKE ?', ["%{$folded}%"])
                ->orWhereRaw('n_code LIKE ?', ["%{$folded}%"])
                ->orWhereRaw($normalizer->foldSeparatorsSql("CONCAT(f_name, ' ', l_name)").' LIKE ?', ["%{$folded}%"])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $new = DB::table('persons')
                ->where('f_name_folded', 'LIKE', "%{$folded}%")
                ->orWhere('l_name_folded', 'LIKE', "%{$folded}%")
                ->orWhere('n_code', 'LIKE', "%{$folded}%")
                ->orWhere('full_name_folded', 'LIKE', "%{$folded}%")
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $this->assertSame($old, $new, "term '{$term}' must match exactly the same rows as the old fold");
        }

        // The fixture must actually exercise the parser, not match nothing.
        $this->assertGreaterThanOrEqual(
            2,
            DB::table('persons')->where('full_name_folded', 'LIKE', '%رضائی%')->count(),
            'the parity matrix must match the Arabic-Yeh/ZWNJ fixture',
        );
    }

    // ==================== 4. Plan pins ====================

    /**
     * `applyPerson` is an `EXISTS` semi-join on `persons`, so the folded
     * columns are what let the subplan build a BitmapOr instead of folding
     * every person row on every keystroke (issue #1482).
     */
    public function test_apply_person_search_uses_the_folded_person_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id]);
        Hardware::factory()->create(['n_code' => $person->n_code]);

        $query = Hardware::query();
        HardwareFilters::applyPerson($query, $person->l_name);

        $plan = implode("\n", $this->planForOr($query->toSql(), $query->getBindings()));

        $this->assertStringContainsString('BitmapOr', $plan, "the folded person OR must be indexable as a whole\n{$plan}");

        foreach ([
            'persons_f_name_folded_trgm',
            'persons_l_name_folded_trgm',
            'persons_full_name_folded_trgm',
            'idx_persons_n_code_trgm',
        ] as $index) {
            $this->assertStringContainsString($index, $plan, "applyPerson should use {$index}\n{$plan}");
        }
    }

    /**
     * `HardwareFilters::applySearch` used to carry the owner name as an
     * `EXISTS` semi-join, which kept the hardware relation on a sequential
     * scan even when the person subplan was indexed. Patch B pre-resolves the
     * matching `n_code`s into an `IN` arm, so every arm is indexable on
     * `hardwares` and the planner builds one BitmapOr.
     */
    public function test_the_hardware_search_pre_resolves_the_person_arm(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $person = Person::factory()->create(['f_name' => 'مهدی', 'l_name' => 'جعفری']);
        Hardware::factory()->create(['n_code' => $person->n_code, 'pc_name' => 'PC-1']);

        $query = Hardware::query();
        HardwareFilters::applySearch($query, 'جعفری');

        $this->assertStringNotContainsString(
            'exists',
            strtolower($query->toSql()),
            'the person arm must be an IN list, not an EXISTS semi-join'
        );

        $plan = implode("\n", $this->planFor($query->toSql(), $query->getBindings()));

        $this->assertStringContainsString('BitmapOr', $plan, "the hardware OR must be indexable as a whole\n{$plan}");
        $this->assertStringContainsString('hardwares_n_code_index', $plan, "the pre-resolved arm should use the n_code index\n{$plan}");
    }

    /**
     * The persons export searches `unit name OR n_code OR full name OR reversed
     * full name`. With the join arm pre-resolved into an `IN` list (Patch B),
     * every arm is indexable on `persons` and the planner builds one BitmapOr.
     *
     * Without Patch B the unit arm is an `EXISTS` semi-join and the outer
     * relation stays a sequential scan even when its subplan is fully indexed —
     * a structural limit, not an index gap.
     */
    public function test_the_persons_export_search_builds_one_bitmap_or(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $unit = Unit::factory()->create(['name' => 'مرکز آزمون']);
        Person::factory()->create(['u_id' => $unit->id]);

        $controller = new PersonsExportController;
        $request = UnitScopedRequest::create('/api/persons/export', 'GET', ['search' => 'مرکز']);

        $query = Person::query();
        (new \ReflectionMethod($controller, 'applySearch'))->invoke($controller, $query, $request);

        $plan = implode("\n", $this->planFor($query->toSql(), $query->getBindings()));

        $this->assertStringContainsString('BitmapOr', $plan, "every export OR arm must be indexable\n{$plan}");

        foreach ([
            // Either u_id B-tree can serve the arm; the covering
            // `persons_u_id_s_id_n_code_idx` is what the planner usually picks.
            'persons_u_id',
            'idx_persons_n_code_trgm',
            'persons_full_name_folded_trgm',
            'persons_full_name_rev_folded_trgm',
        ] as $index) {
            $this->assertStringContainsString($index, $plan, "the export search should use {$index}\n{$plan}");
        }
    }

    /**
     * The most common search is a term that matches PEOPLE and no unit at all.
     * The unit arm must stay an `IN` arm there (empty → `0 = 1`, which the
     * planner folds away); falling back to `whereHas` would keep persons on a
     * sequential scan even though every name arm is indexed.
     */
    public function test_the_persons_export_search_stays_indexable_when_no_unit_matches(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_trgm GIN indexes are PostgreSQL-only.');
        }

        $unit = Unit::factory()->create(['name' => 'مرکز آزمون']);
        Person::factory()->create(['u_id' => $unit->id, 'f_name' => 'مهدی', 'l_name' => 'جعفری']);

        $controller = new PersonsExportController;
        $request = UnitScopedRequest::create('/api/persons/export', 'GET', ['search' => 'جعفری']);

        $query = Person::query();
        (new \ReflectionMethod($controller, 'applySearch'))->invoke($controller, $query, $request);

        $this->assertStringNotContainsString(
            'exists',
            strtolower($query->toSql()),
            'an empty unit arm must not degrade into an EXISTS semi-join'
        );

        $plan = implode("\n", $this->planFor($query->toSql(), $query->getBindings()));

        $this->assertStringContainsString('BitmapOr', $plan, "a name-only export search must stay indexable\n{$plan}");
        $this->assertStringContainsString('persons_full_name_folded_trgm', $plan, "the export search should use the folded full-name index\n{$plan}");
    }

    /**
     * Units (834 rows) and semats (56 rows) are small: a sequential scan is the
     * right plan there. What must be true is that the predicate no longer wraps
     * the column in `regexp_replace()`, so a generated column exists to compare
     * against at all, and the filter uses it.
     */
    public function test_unit_and_semat_filters_use_the_folded_columns(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        $unitQuery = Hardware::query();
        HardwareFilters::applyUnit($unitQuery, 'مرکز');
        $this->assertStringContainsString('name_folded', $unitQuery->toSql());
        $this->assertStringNotContainsString('regexp_replace', $unitQuery->toSql());

        $sematQuery = Hardware::query();
        HardwareFilters::applySemat($sematQuery, 'کارشناس');
        $this->assertStringContainsString('name_folded', $sematQuery->toSql());
        $this->assertStringNotContainsString('regexp_replace', $sematQuery->toSql());
    }

    /**
     * The migration's round trip: `down()` drops the six folded indexes and the
     * six generated columns, and leaves the BARE-column trigram family
     * untouched — `PersonController`, `HrStatsController` and the hardware
     * autocomplete still search those columns directly (#1482).
     */
    public function test_migrating_back_removes_the_folded_columns_and_indexes_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        $foldedIndexes = [
            'persons_f_name_folded_trgm',
            'persons_l_name_folded_trgm',
            'persons_full_name_folded_trgm',
            'persons_full_name_rev_folded_trgm',
            'units_name_folded_trgm',
            'semats_name_folded_trgm',
        ];

        $bareIndexes = [
            'idx_persons_f_name_trgm',
            'idx_persons_l_name_trgm',
            'idx_persons_n_code_trgm',
            'persons_fullname_trgm_idx',
            'persons_fullname_rev_trgm_idx',
            'units_name_trgm_idx',
        ];

        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_14_000001_add_folded_columns_for_persian_search.php',
        ])->assertSuccessful();

        foreach ($foldedIndexes as $index) {
            $this->assertNotContains($index, $this->indexesOf(), "{$index} must be dropped by down()");
        }

        foreach ($bareIndexes as $index) {
            $this->assertContains($index, $this->indexesOf(), "down() must leave the bare-column {$index} alone");
        }

        $this->assertFalse($this->columnExists('persons', 'full_name_folded'), 'down() must drop the generated columns');

        $this->artisan('migrate')->assertSuccessful();

        foreach ($foldedIndexes as $index) {
            $this->assertContains($index, $this->indexesOf(), "a fresh migrate must recreate {$index}");
        }

        $this->assertTrue($this->columnExists('persons', 'full_name_folded'));
    }

    /**
     * @return array<int, string>
     */
    private function indexesOf(): array
    {
        return array_column(
            DB::select(
                'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema()'
            ),
            'indexname'
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ) !== null;
    }

    // ==================== 5. Term / SQL parity ====================

    /**
     * The PHP fold (`normalizeForSearch()`) and the SQL fold
     * (`foldSeparatorsSql()`) must agree character for character, otherwise the
     * term and the stored column drift and the search silently stops matching.
     *
     * Compared against `normalizeForSearch()`, not `foldedTerm()`: the latter
     * also escapes `%`/`_`, which is a LIKE-layer concern, not folding.
     */
    public function test_the_sql_fold_matches_normalize_for_search(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Generated columns rely on PostgreSQL IMMUTABLE expressions.');
        }

        $normalizer = $this->normalizer();

        $matrix = [
            'علي',              // Arabic Yeh
            'علی',
            'کامپیوتر',          // Persian Kaf
            'كامپيوتر',          // Arabic Kaf + Arabic Yeh
            'أحمد',             // Arabic Alef with Hamza above
            'إبراهيم',           // Arabic Alef with Hamza below
            'آموزش',            // Alef with Madda above
            "رضائي\u{200C}پور",  // ZWNJ
            "رضائي\u{200D}پور",  // ZWJ
            '۱۲۳',              // Persian digits
            '٤٥٦',              // Arabic-Indic digits
            'حسین  آباد',        // double space
            'واحد  IT',
        ];

        foreach ($matrix as $input) {
            $sql = DB::selectOne(
                'SELECT '.$normalizer->foldSeparatorsSql('?').' AS folded',
                [$input]
            )->folded;

            $this->assertSame(
                $normalizer->normalizeForSearch($input),
                $sql,
                "the SQL fold and normalizeForSearch() disagree on '{$input}'"
            );
        }
    }
}
