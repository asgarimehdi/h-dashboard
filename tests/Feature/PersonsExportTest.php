<?php

namespace Tests\Feature;

use App\Exports\PersonsExport;
use App\Http\Controllers\Api\PersonsExportController;
use App\Models\Person;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(PersonsExportController::class);

class PersonsExportTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // -----------------------------------------------------------
    //  helpers
    // -----------------------------------------------------------

    /**
     * Download the real xlsx and return its data rows (headings excluded),
     * each row keyed A..Z so columns can be read by heading.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFromRoute(array $query = []): array
    {
        $path = $this->downloadedFilePath($query);

        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        array_shift($rows);

        $spreadsheet->disconnectWorksheets();

        return array_values($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    protected function column(array $rows, string $heading): array
    {
        $column = array_search($heading, (new PersonsExport(collect()))->headings(), true);

        $this->assertNotFalse($column, "Unknown heading [{$heading}].");
        $letter = Coordinate::stringFromColumnIndex($column + 1);

        return array_map(fn (array $row) => $row[$letter] ?? null, $rows);
    }

    /**
     * The national codes in the sheet, sorted, so an assertion pins the exact
     * row set. `createUserWithUnit()` also creates a Person for the user, so a
     * count or a name is not on its own enough to identify the target.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    protected function codes(array $rows): array
    {
        $codes = $this->column($rows, 'کد ملی');
        sort($codes);

        return $codes;
    }

    protected function downloadedFilePath(array $query = []): string
    {
        $response = $this->get(route('kargozini.persons.export', $query));
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        copy($path, $tmp = tempnam(sys_get_temp_dir(), 'persons-export-').'.xlsx');

        return $tmp;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createPerson(array $data = []): Person
    {
        return Person::factory()->create(array_merge([
            'n_code' => '0012345678',
            'f_name' => 'مهدی',
            'l_name' => 'عسگری',
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
        ], $data));
    }

    /**
     * A person whose every lookup and unit row is gone. Built in memory —
     * the FKs are `onDelete('restrict')`, so a real row cannot be orphaned
     * through the ORM without dropping the constraint first.
     */
    protected function orphanPerson(): Person
    {
        $person = new Person([
            'n_code' => '0011111111',
            'f_name' => 'یتیم',
            'l_name' => 'داده',
        ]);
        $person->id = 1;
        $person->t_id = 900;
        $person->e_id = 900;
        $person->s_id = 900;
        $person->r_id = 900;
        $person->u_id = 900;
        $person->hire_date = null;
        $person->status = 'active';

        return $person;
    }

    // -----------------------------------------------------------
    //  Slice 1 — route + authorization
    // -----------------------------------------------------------

    public function test_export_route_exists(): void
    {
        $this->assertTrue(
            Route::has('kargozini.persons.export'),
            'Route kargozini.persons.export is not registered.'
        );
    }

    public function test_export_route_requires_auth(): void
    {
        $this->get(route('kargozini.persons.export'))->assertRedirect(route('login'));
    }

    public function test_export_route_requires_kargozini_permission(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->actingAs($user)
            ->get(route('kargozini.persons.export'))
            ->assertForbidden();
    }

    public function test_export_route_returns_xlsx_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $this->actingAs($user)
            ->get(route('kargozini.persons.export'))
            ->assertOk()
            ->assertDownload();
    }

    // -----------------------------------------------------------
    //  Slice 2 — headings + title
    // -----------------------------------------------------------

    public function test_export_headings_are_the_persian_labels(): void
    {
        $this->assertSame([
            'کد ملی',
            'نام',
            'نام خانوادگی',
            'نام کامل',
            'سمت',
            'تحصیلات',
            'نوع استخدام',
            'ردیف سازمانی',
            'واحد سازمانی',
            'وضعیت',
            'تاریخ تولد',
            'تاریخ استخدام',
        ], (new PersonsExport(collect()))->headings());
    }

    public function test_export_title_is_persian(): void
    {
        $this->assertSame('پرسنل', (new PersonsExport(collect()))->title());
    }

    // -----------------------------------------------------------
    //  Slice 3 — access scoping
    // -----------------------------------------------------------

    public function test_export_contains_only_persons_inside_the_callers_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);

        $outsider = Unit::factory()->create(['name' => 'واحد بیرون از دسترس']);

        $this->createPerson([
            'n_code' => '0098765432',
            'f_name' => 'خارجی',
            'l_name' => 'دامنه',
            'u_id' => $outsider->id,
        ]);

        $this->actingAs($user);

        $names = $this->column($this->rowsFromRoute(), 'نام');

        $this->assertContains('مهدی', $names);
        $this->assertNotContains('خارجی', $names);
    }

    public function test_export_includes_sub_units_of_the_callers_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);

        $child = Unit::factory()->create(['name' => 'زیرمجموعه', 'parent_id' => $unit->id]);

        $this->createPerson(['n_code' => '0011111111', 'f_name' => 'زیرمجموعه‌ای', 'l_name' => 'پرسنل', 'u_id' => $child->id]);

        $this->actingAs($user);

        $this->assertContains('0011111111', $this->codes($this->rowsFromRoute()));
    }

    public function test_export_returns_only_headers_when_scope_is_empty(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'u_id' => $unit->id]);

        $this->actingAs($user);

        // Drop the session context and every unit link so accessibleUnitIds()
        // resolves to an empty scope.
        Session::forget('current_unit_id');
        $user->units()->detach();
        $user->person->update(['u_id' => null]);

        $response = $this->get(route('kargozini.persons.export'));

        $response->assertOk();
        $response->assertDownload();
        $this->assertSame([], $this->rowsFromRoute());
    }

    // -----------------------------------------------------------
    //  Slice 4 — filters
    // -----------------------------------------------------------

    public function test_export_without_filters_returns_every_person_in_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        // The helper's own person for the user is in the same unit, so the
        // sheet carries three rows, not two.
        $this->assertCount(3, $this->rowsFromRoute());
        $this->assertContains('0012345678', $this->codes($this->rowsFromRoute()));
        $this->assertContains('0098765432', $this->codes($this->rowsFromRoute()));
    }

    public function test_export_applies_the_text_search_filter(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(['مهدی'], $this->column($this->rowsFromRoute(['search' => 'عسگری']), 'نام'));
    }

    public function test_export_search_matches_the_reversed_name_order(): void
    {
        // #494: "عسگری مهدی" must find «مهدی عسگری».
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(['مهدی'], $this->column($this->rowsFromRoute(['search' => 'عسگری مهدی']), 'نام'));
    }

    public function test_export_search_matches_a_persons_national_code(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(['0098765432'], $this->column($this->rowsFromRoute(['search' => '0098765']), 'کد ملی'));
    }

    public function test_export_normalizes_persian_input_before_matching(): void
    {
        // normalizeForSearch folds Arabic ی/ک and foldedTerm() escapes the LIKE
        // wildcards on top of it, so the export must match on the same term the
        // list does.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(['مهدی'], $this->column($this->rowsFromRoute(['search' => 'عسگري']), 'نام'));
    }

    public function test_export_escapes_like_wildcards_in_the_search_term(): void
    {
        // A raw `%` would otherwise match every row.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame([], $this->rowsFromRoute(['search' => '%']));
    }

    public function test_export_search_folds_a_name_column_written_without_the_model_hook(): void
    {
        // #815 folded the unit column because Unit has no saving hook; the same
        // argument applies to a person row that reached the table by any path
        // other than the model's `saving` hook — the stored text then still
        // carries the unfodded spelling and only a folded column can match it.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        // The helper's person is created through the model and shares this
        // search scope, so its name is pinned to something the «عسگری» filter
        // cannot match — assertSame() below pins the exact row set.
        $user->person->update(['f_name' => 'بی‌نام', 'l_name' => 'آزمون']);

        // Inserted straight to the table: Person::$saving would have normalized
        // these names, which is exactly what this row must be missing.
        DB::table('persons')->insert([
            'n_code' => '0012345678',
            'f_name' => 'عسگري',   // Arabic yeh, never normalized
            'l_name' => 'كريبى',   // Arabic kaf, never normalized
            // Pinned so the helper's own faker person cannot collide with the
            // «عسگری» filter and make the exact-row-set assertion flake.
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unit->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No explicit id was supplied, so the sequence already advanced past
        // this row — resyncSequence() is only for hand-seeded ids.
        $this->actingAs($user);

        // Searched with the Persian spelling; only the folded column bridges
        // the two.
        $this->assertSame(
            ['0012345678'],
            $this->codes($this->rowsFromRoute(['search' => 'عسگری']))
        );
    }

    public function test_export_does_not_error_on_a_zwnj_search(): void
    {
        // #940: ZWNJ (U+200C) is the standard Persian compound separator, so this
        // is ordinary typing on the screen the export link sits on. It must not
        // 500.
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $this->actingAs($user);

        $response = $this->get(route('kargozini.persons.export', [
            'search' => "خانه\u{200C}بهداشت",
        ]));

        $response->assertOk();
        $response->assertDownload();
    }

    public function test_export_search_matches_a_unit_name_containing_a_zwnj_compound(): void
    {
        // Unit has no saving hook, so its name keeps the ZWNJ verbatim and the
        // stored column is what has to be folded — proving the match, not just
        // the absence of a crash.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        // Pinned: UnitFactory draws from 15 names and 'خانه بهداشت روستایی'
        // contains BOTH search terms, so a random $unit would match the filter
        // and flake this exact-row-set assertion (~1/15 runs).
        $unit->update(['name' => 'واحد مالی و اداری']);

        $clinic = Unit::factory()->create([
            'name' => "خانه\u{200C}بهداشت مرکزی",
            'parent_id' => $unit->id,
        ]);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $clinic->id,
        ]);
        $this->createPerson([
            'n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id,
        ]);

        $this->actingAs($user);

        $this->assertSame(
            ['0012345678'],
            $this->codes($this->rowsFromRoute(['search' => "خانه\u{200C}بهداشت"]))
        );
    }

    public function test_export_folds_each_search_term_exactly_once(): void
    {
        // The `%`-only test above cannot tell single from double escaping: it
        // asserts an empty sheet, and a double-escaped `%` matches nothing too.
        // This term must BOTH escape a wildcard and match something — folded
        // once it binds `%100\%%` and finds «ظرفیت 100%», folded twice it binds
        // `%100\\%%` and finds neither row.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'ظرفیت 100', 'l_name' => 'مهدی', 'u_id' => $unit->id,
        ]);
        $this->createPerson([
            'n_code' => '0098765432', 'f_name' => 'ظرفیت 100%', 'l_name' => 'زهرا', 'u_id' => $unit->id,
        ]);

        $this->actingAs($user);

        $this->assertSame(
            ['0098765432'],
            $this->codes($this->rowsFromRoute(['search' => '100%']))
        );
    }

    public function test_export_search_ands_every_term_of_a_zwnj_and_space_search(): void
    {
        // The ZWNJ becomes one more term, so this is three AND-ed terms. Only
        // the unit carrying all three may come back, which also pins that the
        // fold does not reorder or drop a term.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        // Pinned for the same reason as the ZWNJ-compound test above: a random
        // factory name must never contribute a search term to this assertion.
        $unit->update(['name' => 'واحد مالی و اداری']);

        $partial = Unit::factory()->create(['name' => "خانه\u{200C}بهداشت", 'parent_id' => $unit->id]);
        $full = Unit::factory()->create(['name' => "مرکز خانه\u{200C}بهداشت", 'parent_id' => $unit->id]);

        $this->createPerson(['n_code' => '0012345678', 'u_id' => $partial->id]);
        $this->createPerson(['n_code' => '0098765432', 'u_id' => $full->id]);

        $this->actingAs($user);

        $this->assertSame(
            ['0098765432'],
            $this->codes($this->rowsFromRoute(['search' => "خانه\u{200C}بهداشت مرکز"]))
        );
    }

    public function test_export_search_matches_a_national_code_typed_with_persian_digits(): void
    {
        // digitMap() folds ۰-۹ to Latin on the term side, so a national code
        // typed on a Persian keyboard still matches the stored Latin digits.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(
            ['0098765432'],
            $this->codes($this->rowsFromRoute(['search' => '۰۰۹۸۷۶۵']))
        );
    }

    public function test_export_applies_the_unit_filter(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $other = Unit::factory()->create(['name' => 'واحد دوم', 'parent_id' => $unit->id]);

        $this->createPerson(['n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $other->id]);

        $this->actingAs($user);

        $this->assertSame(
            ['0012345678', $user->n_code],
            $this->codes($this->rowsFromRoute(['filter_u_id' => $unit->id]))
        );
    }

    public function test_export_applies_the_lookup_filters_together(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $semat = $this->insertLookup('semats', 'کارشناس فنی');
        $tahsil = $this->insertLookup('tahsils', 'کارشناسی ارشد');
        $estekhdam = $this->insertLookup('estekhdams', 'رسمی');
        $radif = $this->insertLookup('radifs', 'ردیف سازمانی ۳');

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری',
            'u_id' => $unit->id, 's_id' => $semat, 't_id' => $tahsil,
            'e_id' => $estekhdam, 'r_id' => $radif,
        ]);
        $this->createPerson(['n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $unit->id]);

        $this->actingAs($user);

        $this->assertSame(
            ['مهدی'],
            $this->column($this->rowsFromRoute(['filter_s_id' => $semat, 'filter_t_id' => $tahsil]), 'نام')
        );
        $this->assertSame(
            ['مهدی'],
            $this->column($this->rowsFromRoute(['filter_e_id' => $estekhdam, 'filter_r_id' => $radif]), 'نام')
        );
    }

    public function test_export_unit_filter_cannot_escape_the_callers_scope(): void
    {
        // The unit filter narrows the scope; it must never widen it. A unit id
        // outside accessibleUnitIds() must match no rows.
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $outsider = Unit::factory()->create(['name' => 'واحد بیرون از دسترس']);

        $this->createPerson([
            'n_code' => '0098765432', 'f_name' => 'خارجی', 'l_name' => 'دامنه', 'u_id' => $outsider->id,
        ]);

        $this->actingAs($user);

        $this->assertSame([], $this->rowsFromRoute(['filter_u_id' => $outsider->id]));
    }

    // -----------------------------------------------------------
    //  Slice 5 — columns
    // -----------------------------------------------------------

    public function test_export_writes_lookup_and_unit_names_into_the_row(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $unit->update(['name' => 'بیمارستان شهید فهمیده']);

        $semat = $this->insertLookup('semats', 'کارشناس فنی');
        $tahsil = $this->insertLookup('tahsils', 'کارشناسی ارشد');
        $estekhdam = $this->insertLookup('estekhdams', 'رسمی');
        $radif = $this->insertLookup('radifs', 'ردیف سازمانی ۳');

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری',
            'u_id' => $unit->id, 's_id' => $semat, 't_id' => $tahsil,
            'e_id' => $estekhdam, 'r_id' => $radif,
        ]);

        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        // Pinned on the target's national code: the helper's own person is in
        // the same unit, so a positional assertion would read the wrong row.
        $this->assertSame(['0012345678'], $this->forCode($rows, 'کد ملی', '0012345678'));
        $this->assertSame(['مهدی'], $this->forCode($rows, 'نام', '0012345678'));
        $this->assertSame(['عسگری'], $this->forCode($rows, 'نام خانوادگی', '0012345678'));
        $this->assertSame(['مهدی عسگری'], $this->forCode($rows, 'نام کامل', '0012345678'));
        $this->assertSame(['کارشناس فنی'], $this->forCode($rows, 'سمت', '0012345678'));
        $this->assertSame(['کارشناسی ارشد'], $this->forCode($rows, 'تحصیلات', '0012345678'));
        $this->assertSame(['رسمی'], $this->forCode($rows, 'نوع استخدام', '0012345678'));
        $this->assertSame(['ردیف سازمانی ۳'], $this->forCode($rows, 'ردیف سازمانی', '0012345678'));
        $this->assertSame(['بیمارستان شهید فهمیده'], $this->forCode($rows, 'واحد سازمانی', '0012345678'));
    }

    public function test_export_separates_same_named_units_in_different_branches(): void
    {
        // #756: a bare unit name cannot tell two «پایگاه»s apart, so the cell
        // carries the full breadcrumb — the same contract as the units
        // export's «مسیر کامل» column.
        ['user' => $user, 'unit' => $root] = $this->createUserWithUnit(['kargozini']);

        $central = Unit::factory()->create(['name' => 'شبکه بهداشت مرکزی', 'parent_id' => $root->id]);
        $south = Unit::factory()->create(['name' => 'شبکه بهداشت جنوب', 'parent_id' => $root->id]);
        $clinicA = Unit::factory()->create(['name' => 'پایگاه', 'parent_id' => $central->id]);
        $clinicB = Unit::factory()->create(['name' => 'پایگاه', 'parent_id' => $south->id]);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $clinicA->id,
        ]);
        $this->createPerson([
            'n_code' => '0098765432', 'f_name' => 'زهرا', 'l_name' => 'کریمی', 'u_id' => $clinicB->id,
        ]);

        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        $this->assertSame(
            ["{$root->name} > شبکه بهداشت مرکزی > پایگاه"],
            $this->forCode($rows, 'واحد سازمانی', '0012345678')
        );
        $this->assertSame(
            ["{$root->name} > شبکه بهداشت جنوب > پایگاه"],
            $this->forCode($rows, 'واحد سازمانی', '0098765432')
        );
    }

    public function test_export_writes_a_dash_for_a_missing_lookup_or_unit(): void
    {
        // The FKs are `onDelete('restrict')`, so a real row cannot be orphaned
        // through the ORM — the fallback is proved on an in-memory person whose
        // lookup rows simply do not exist.
        $person = $this->orphanPerson();
        $export = new PersonsExport(collect([$person]));

        $row = $export->map($person);

        $this->assertSame('-', $row[4], 'سمت');
        $this->assertSame('-', $row[5], 'تحصیلات');
        $this->assertSame('-', $row[6], 'نوع استخدام');
        $this->assertSame('-', $row[7], 'ردیف سازمانی');
        $this->assertSame('-', $row[8], 'واحد سازمانی');
    }

    public function test_export_writes_a_dash_for_a_missing_hire_date(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id,
        ]);

        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        // Two rows: the helper's own person (no hire date) plus the target.
        // The date column is asserted on the target's code, not positionally.
        $this->assertSame(['-'], $this->forCode($rows, 'تاریخ استخدام', '0012345678'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    protected function forCode(array $rows, string $heading, string $nCode): array
    {
        $codeIndex = array_search('کد ملی', (new PersonsExport(collect()))->headings(), true);
        $letter = Coordinate::stringFromColumnIndex($codeIndex + 1);

        $filtered = array_values(array_filter(
            $rows,
            fn (array $row) => ($row[$letter] ?? null) === $nCode
        ));

        $this->assertCount(1, $filtered, "Expected exactly one row for n_code {$nCode}.");

        return $this->column($filtered, $heading);
    }

    public function test_export_writes_the_hire_date(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری',
            'u_id' => $unit->id, 'hire_date' => '2021-03-15',
        ]);

        $this->actingAs($user);

        // 2021-03-15 Gregorian is 25 Esfand 1399 (Nowruz 1400 = 2021-03-20).
        $this->assertSame(['1399/12/25'], $this->forCode($this->rowsFromRoute(), 'تاریخ استخدام', '0012345678'));
    }

    public function test_export_writes_the_birth_date_in_the_hire_date_format(): void
    {
        // #756: «تاریخ تولد» must use the same Jalali Y/m/d the rest of the
        // sheet uses — two date formats in one file invite misreading.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری',
            'u_id' => $unit->id, 'birth_date' => '1985-09-23',
        ]);

        $this->actingAs($user);

        // 1985-09-23 Gregorian is 1 Mehr 1364.
        $this->assertSame(['1364/07/01'], $this->forCode($this->rowsFromRoute(), 'تاریخ تولد', '0012345678'));
    }

    public function test_export_writes_a_dash_for_a_missing_birth_date(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        $this->createPerson([
            'n_code' => '0012345678', 'f_name' => 'مهدی', 'l_name' => 'عسگری', 'u_id' => $unit->id,
        ]);

        $this->actingAs($user);

        // PersonFactory draws no birth_date, so the cell must fall back to
        // the file's own '-' instead of an empty string.
        $this->assertSame(['-'], $this->forCode($this->rowsFromRoute(), 'تاریخ تولد', '0012345678'));
    }

    public function test_export_uses_a_dash_for_an_empty_full_name(): void
    {
        // Person::$name falls back to «—» when both name parts are blank; the
        // export must not hand Excel a row of whitespace.
        $person = new Person(['n_code' => '0011111111']);
        $person->id = 1;
        $person->f_name = '   ';
        $person->l_name = '';
        $person->t_id = 1;
        $person->e_id = 1;
        $person->s_id = 1;
        $person->r_id = 1;
        $person->u_id = 1;

        $export = new PersonsExport(collect([$person]));

        $this->assertSame('-', $export->map($person)[3]);
    }

    public function test_export_is_right_to_left(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);
        $this->actingAs($user);

        $spreadsheet = IOFactory::load($this->downloadedFilePath());

        $this->assertTrue(
            $spreadsheet->getActiveSheet()->getRightToLeft(),
            'The sheet must be marked right-to-left.'
        );
    }

    public function test_export_does_not_issue_a_query_per_row(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);

        foreach (range(1, 6) as $i) {
            $this->createPerson([
                'n_code' => str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'u_id' => $unit->id,
            ]);
        }

        $this->actingAs($user);

        $this->assertNoNPlusOne(fn () => $this->rowsFromRoute(), 10);
    }

    // -----------------------------------------------------------
    //  Slice 6 — the button on the personnel page
    // -----------------------------------------------------------

    public function test_persons_page_shows_the_export_button(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $this->actingAs($user)
            ->get('/kargozini/persons')
            ->assertOk()
            ->assertSee(route('kargozini.persons.export'), escape: false);
    }

    public function test_export_button_is_a_plain_link_not_a_livewire_action(): void
    {
        // Livewire cannot return a file download, so the button must not use
        // wire:click — it has to be a normal anchor.
        ['user' => $user] = $this->createUserWithUnit(['kargozini']);

        $this->actingAs($user);

        $html = (string) $this->get('/kargozini/persons')->assertOk()->getContent();

        $this->assertTrue(
            (bool) preg_match('/<a[^>]+href="'.preg_quote(route('kargozini.persons.export'), '/').'"[^>]*>/', $html),
            'Export control must be an <a href> pointing at the export route.'
        );
    }

    protected function insertLookup(string $table, string $name): int
    {
        $id = DB::table($table)->insertGetId(['name' => $name]);
        $this->resyncSequence($table);

        return $id;
    }
}
