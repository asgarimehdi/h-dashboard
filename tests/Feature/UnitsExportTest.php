<?php

namespace Tests\Feature;

use App\Exports\UnitsExport;
use App\Http\Controllers\Api\UnitsExportController;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(UnitsExportController::class);

class UnitsExportTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->seedUnitTypes();
        $this->seedRegions();
    }

    protected function seedUnitTypes(): void
    {
        DB::table('unit_types')->insert([
            ['id' => 1, 'name' => 'وزارت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'دانشگاه علوم پزشکی', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'معاونت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'شبکه بهداشت', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->resyncSequence('unit_types');
    }

    protected function seedRegions(): void
    {
        DB::table('regions')->insert([
            ['id' => 1, 'name' => 'استان تست', 'type' => 'province', 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'شهرستان الف', 'type' => 'county', 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->resyncSequence('regions');
    }

    /**
     * Download the real xlsx and return its data rows (headings excluded),
     * each row keyed A..Z so columns can be read by heading.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFromRoute(): array
    {
        $path = $this->downloadedFilePath();

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
        $column = array_search($heading, (new UnitsExport(collect(), []))->headings(), true);

        $this->assertNotFalse($column, "Unknown heading [{$heading}].");
        $letter = Coordinate::stringFromColumnIndex($column + 1);

        return array_map(
            fn (array $row) => $row[$letter] ?? null,
            $rows
        );
    }

    protected function downloadedFilePath(): string
    {
        $response = $this->get(route('units.export'));
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        copy($path, $tmp = tempnam(sys_get_temp_dir(), 'units-export-').'.xlsx');

        return $tmp;
    }

    // -----------------------------------------------------------
    //  Slice 1 — route + authorization
    // -----------------------------------------------------------

    public function test_export_route_exists(): void
    {
        $this->assertTrue(
            Route::has('units.export'),
            'Route units.export is not registered.'
        );
    }

    public function test_export_route_requires_auth(): void
    {
        $this->get(route('units.export'))->assertRedirect(route('login'));
    }

    public function test_export_route_requires_organization_permission(): void
    {
        ['user' => $basicUser] = $this->createUserWithUnit();
        $this->actingAs($basicUser);

        $this->get(route('units.export'))->assertForbidden();
    }

    public function test_export_route_returns_xlsx_for_authorized_user(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'is_active' => true]);
        $this->actingAs($user);

        $response = $this->get(route('units.export'));

        $response->assertOk();
        $response->assertDownload();
    }

    // -----------------------------------------------------------
    //  Slice 2 — headings + row shape
    // -----------------------------------------------------------

    public function test_export_headings_are_the_eight_persian_labels(): void
    {
        $export = new UnitsExport(collect(), []);

        $this->assertSame([
            'شناسه',
            'نام واحد',
            'نوع واحد',
            'شهرستان',
            'والد مستقیم',
            'مسیر کامل',
            'سطح',
            'وضعیت',
        ], $export->headings());
    }

    public function test_export_title_is_persian(): void
    {
        $this->assertSame('واحدها', (new UnitsExport(collect(), []))->title());
    }

    public function test_export_map_returns_one_row_per_column(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'name' => 'دانشگاه تست', 'region_id' => 1, 'is_active' => true]);
        $this->actingAs($user);

        $export = new UnitsExport(
            collect([$unit->fresh()]),
            [$unit->id => ['path' => 'دانشگاه تست', 'depth' => 0, 'parent_name' => '']]
        );

        $row = $export->map($unit->fresh());

        $this->assertCount(8, $row);
        $this->assertSame($unit->id, $row[0]);
        $this->assertSame('دانشگاه تست', $row[1]);
        $this->assertSame('دانشگاه علوم پزشکی', $row[2]);
        $this->assertSame('-', $row[3], 'the unit is in a province, not a county');
        $this->assertSame('-', $row[4], 'a root unit has no parent name');
        $this->assertSame('دانشگاه تست', $row[5]);
        $this->assertSame(0, $row[6]);
        $this->assertSame('فعال', $row[7]);
    }

    public function test_export_marks_inactive_units(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'name' => 'واحد غیرفعال', 'is_active' => false]);
        $this->actingAs($user);

        $export = new UnitsExport(
            collect([$unit->fresh()]),
            [$unit->id => ['path' => 'واحد غیرفعال', 'depth' => 0, 'parent_name' => '']]
        );

        $this->assertSame('غیرفعال', $export->map($unit->fresh())[7]);
    }

    public function test_export_map_uses_unit_type_name(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 3, 'is_active' => true]);
        $this->actingAs($user);

        $unit = $unit->fresh('unitType');
        $export = new UnitsExport(
            collect([$unit]),
            [$unit->id => ['path' => $unit->name, 'depth' => 0, 'parent_name' => '']]
        );

        $this->assertSame('معاونت بهداشت', $export->map($unit)[2]);
    }

    // -----------------------------------------------------------
    //  Slice 3 — access scoping
    // -----------------------------------------------------------

    public function test_export_contains_only_units_inside_the_callers_scope(): void
    {
        ['user' => $user, 'unit' => $root] = $this->createUserWithUnit(['organization']);
        $root->update(['unit_type_id' => 1, 'name' => 'وزارت تست', 'is_active' => true]);

        $child = Unit::factory()->create([
            'name' => 'زیرمجموعه تست',
            'unit_type_id' => 2,
            'parent_id' => $root->id,
            'region_id' => 1,
            'is_active' => true,
        ]);

        $outsider = Unit::factory()->create([
            'name' => 'واحد بیرون از دسترس',
            'unit_type_id' => 2,
            'parent_id' => null,
            'region_id' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $rows = $this->column($this->rowsFromRoute(), 'نام واحد');

        $this->assertContains('وزارت تست', $rows);
        $this->assertContains('زیرمجموعه تست', $rows);
        $this->assertNotContains('واحد بیرون از دسترس', $rows);
        $this->assertNotNull($child);
    }

    public function test_export_returns_only_headers_when_scope_is_empty(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'is_active' => true]);
        $this->actingAs($user);

        // Drop the session context so accessibleUnitIds() resolves to the
        // user's own units only — still scoped, so force a truly empty scope.
        Session::forget('current_unit_id');
        $user->units()->detach();
        $user->person->update(['u_id' => null]);

        $response = $this->get(route('units.export'));

        $response->assertOk();
        $response->assertDownload();
        $this->assertSame([], $this->rowsFromRoute());
    }

    // -----------------------------------------------------------
    //  Slice 4 — breadcrumb, depth, depth-first order
    // -----------------------------------------------------------

    public function test_export_builds_full_path_depth_and_parent_name(): void
    {
        ['user' => $user, 'unit' => $ministry] = $this->createUserWithUnit(['organization']);
        $ministry->update(['unit_type_id' => 1, 'name' => 'وزارت بهداشت', 'is_active' => true]);

        $university = Unit::factory()->create([
            'name' => 'دانشگاه علوم پزشکی زنجان',
            'unit_type_id' => 2,
            'parent_id' => $ministry->id,
            'region_id' => 1,
            'is_active' => true,
        ]);

        $hospital = Unit::factory()->create([
            'name' => 'بیمارستان شهید فهمیده',
            'unit_type_id' => 4,
            'parent_id' => $university->id,
            'region_id' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        $this->assertSame(
            [
                'وزارت بهداشت',
                'وزارت بهداشت > دانشگاه علوم پزشکی زنجان',
                'وزارت بهداشت > دانشگاه علوم پزشکی زنجان > بیمارستان شهید فهمیده',
            ],
            $this->column($rows, 'مسیر کامل')
        );
        $this->assertSame(
            ['وزارت بهداشت', 'دانشگاه علوم پزشکی زنجان', 'بیمارستان شهید فهمیده'],
            $this->column($rows, 'نام واحد')
        );
        $this->assertSame([0, 1, 2], array_map('intval', $this->column($rows, 'سطح')));
        $this->assertSame(
            ['-', 'وزارت بهداشت', 'دانشگاه علوم پزشکی زنجان'],
            $this->column($rows, 'والد مستقیم')
        );
        $this->assertSame(['وزارت بهداشت', 'دانشگاه علوم پزشکی', 'شبکه بهداشت'], $this->column($rows, 'نوع واحد'));
        $this->assertSame(['فعال', 'فعال', 'فعال'], $this->column($rows, 'وضعیت'));
        $this->assertNotNull($hospital);
    }

    public function test_export_orders_parents_before_children(): void
    {
        ['user' => $user, 'unit' => $ministry] = $this->createUserWithUnit(['organization']);
        $ministry->update(['unit_type_id' => 1, 'name' => 'الف', 'is_active' => true]);

        // Deliberately create the child before the parent in id order.
        $child = Unit::factory()->create([
            'name' => 'ب فرزند',
            'unit_type_id' => 2,
            'parent_id' => $ministry->id,
            'region_id' => 1,
            'is_active' => true,
        ]);
        $ministry->update(['name' => 'ج والد']);

        $this->actingAs($user);

        $order = $this->column($this->rowsFromRoute(), 'نام واحد');

        $this->assertLessThan(
            array_search('ب فرزند', $order, true),
            array_search('ج والد', $order, true),
            'Parent must be listed before its child.'
        );
        $this->assertNotNull($child);
    }

    public function test_export_breadcrumb_keeps_ancestors_above_the_callers_scope(): void
    {
        // Caller is scoped to a leaf unit; its ancestors are not in scope but
        // still name the path, matching what the tree page renders.
        $ministry = Unit::factory()->create([
            'name' => 'وزارت بالادست',
            'unit_type_id' => 1,
            'parent_id' => null,
            'is_active' => true,
        ]);
        $university = Unit::factory()->create([
            'name' => 'دانشگاه بالادست',
            'unit_type_id' => 2,
            'parent_id' => $ministry->id,
            'is_active' => true,
        ]);

        ['user' => $user, 'unit' => $leaf] = $this->createUserWithUnit(['organization']);
        $leaf->update([
            'name' => 'واحد برگ',
            'unit_type_id' => 3,
            'parent_id' => $university->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $paths = $this->column($this->rowsFromRoute(), 'مسیر کامل');

        $this->assertSame(['وزارت بالادست > دانشگاه بالادست > واحد برگ'], $paths);
        $this->assertSame([2], array_map('intval', $this->column($this->rowsFromRoute(), 'سطح')));
    }

    // -----------------------------------------------------------
    //  Slice 5 — county column
    // -----------------------------------------------------------

    public function test_export_headings_include_the_county_column(): void
    {
        $headings = (new UnitsExport(collect(), []))->headings();

        $this->assertContains('شهرستان', $headings);
    }

    public function test_export_writes_the_county_name_for_a_unit_in_a_county(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 4, 'name' => 'شبکه بهداشت الف', 'region_id' => 2, 'is_active' => true]);
        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        $this->assertSame(['شهرستان الف'], $this->column($rows, 'شهرستان'));
    }

    public function test_export_county_column_is_dash_for_a_unit_in_a_province(): void
    {
        // A unit attached to a PROVINCE has no county of its own — the county
        // column must not silently inherit the province name.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'name' => 'دانشگاه تست', 'region_id' => 1, 'is_active' => true]);
        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        $this->assertSame(['-'], $this->column($rows, 'شهرستان'));
    }

    public function test_export_county_column_is_dash_when_the_unit_has_no_region(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'name' => 'واحد بدون منطقه', 'region_id' => null, 'is_active' => true]);
        $this->actingAs($user);

        $rows = $this->rowsFromRoute();

        $this->assertSame(['-'], $this->column($rows, 'شهرستان'));
    }

    public function test_export_county_column_groups_units_of_the_same_county_together(): void
    {
        // The point of the column: filtering in Excel by one county name must
        // select every unit in that county.
        $county = DB::table('regions')->insertGetId([
            'name' => 'شهرستان مشترک',
            'type' => 'county',
            'parent_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->resyncSequence('regions');

        $other = DB::table('regions')->insertGetId([
            'name' => 'شهرستان دیگر',
            'type' => 'county',
            'parent_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->resyncSequence('regions');

        ['user' => $user, 'unit' => $root] = $this->createUserWithUnit(['organization']);
        $root->update(['unit_type_id' => 1, 'name' => 'وزارت تست', 'region_id' => null, 'is_active' => true]);

        Unit::factory()->create([
            'name' => 'شبکه الف', 'unit_type_id' => 4, 'parent_id' => $root->id,
            'region_id' => $county, 'is_active' => true,
        ]);
        Unit::factory()->create([
            'name' => 'بیمارستان ب', 'unit_type_id' => 4, 'parent_id' => $root->id,
            'region_id' => $county, 'is_active' => true,
        ]);
        Unit::factory()->create([
            'name' => 'شبکه ج', 'unit_type_id' => 4, 'parent_id' => $root->id,
            'region_id' => $other, 'is_active' => true,
        ]);

        $this->actingAs($user);

        $counties = $this->column($this->rowsFromRoute(), 'شهرستان');

        $this->assertCount(4, $counties, 'the ministry plus its three children');
        $this->assertSame(['-'], [$counties[0]], 'the ministry has no county');
        $this->assertSame(
            2,
            count(array_filter($counties, fn ($c) => $c === 'شهرستان مشترک')),
            'filtering the column by one county must select every unit in it'
        );
        $this->assertContains('شهرستان دیگر', $counties);
    }

    // -----------------------------------------------------------
    //  Slice 6 — cycle guard on user-editable parent_id
    // -----------------------------------------------------------

    public function test_export_survives_a_cyclic_parent_chain(): void
    {
        // Built from in-memory models, not seeded rows: a real cyclic row hangs
        // Unit::descendantIds(), whose WITH RECURSIVE ... UNION ALL never
        // terminates on a cycle (pre-existing app-wide hazard — the reason the
        // export needs its own guard). The guard under test is the export's.
        $a = new Unit(['name' => 'الف']);
        $a->id = 1;
        $a->parent_id = 2;
        $a->is_active = true;

        $b = new Unit(['name' => 'ب']);
        $b->id = 2;
        $b->parent_id = 1; // A -> B -> A
        $b->is_active = true;

        $map = [
            1 => ['name' => 'الف', 'parent_id' => 2],
            2 => ['name' => 'ب', 'parent_id' => 1],
        ];

        $hierarchy = $this->invokeBuildHierarchy(collect([$a, $b]), $map);
        $ordered = $this->invokeOrderDepthFirst(collect([$a, $b]));

        $this->assertCount(2, $ordered, 'No unit may be dropped or duplicated by a cycle.');
        $this->assertEqualsCanonicalizing([1, 2], $ordered->pluck('id')->all());

        foreach ($hierarchy as $meta) {
            $this->assertNotEmpty($meta['path'], 'A cycle must still produce a breadcrumb.');
            $this->assertLessThanOrEqual(2, $meta['depth']);
        }
    }

    /**
     * @param  Collection<int, Unit>  $units
     * @param  array<int, array{name: string, parent_id: int|null}>  $map
     * @return array<int, array{path: string, depth: int, parent_name: string}>
     */
    protected function invokeBuildHierarchy(Collection $units, array $map): array
    {
        $controller = new UnitsExportController;
        $method = new \ReflectionMethod($controller, 'buildHierarchy');
        $method->setAccessible(true);

        return $method->invoke($controller, $units, $map);
    }

    /**
     * @param  Collection<int, Unit>  $units
     * @return Collection<int, Unit>
     */
    protected function invokeOrderDepthFirst(Collection $units): Collection
    {
        $controller = new UnitsExportController;
        $method = new \ReflectionMethod($controller, 'orderDepthFirst');
        $method->setAccessible(true);

        return $method->invoke($controller, $units, []);
    }

    // -----------------------------------------------------------
    //  Slice 6 — export button on the management page
    // -----------------------------------------------------------

    public function test_units_page_shows_the_export_button(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'is_active' => true]);
        $this->actingAs($user);

        $this->get('/units')
            ->assertOk()
            ->assertSee(route('units.export'), escape: false);
    }

    public function test_export_button_is_a_plain_link_not_a_livewire_action(): void
    {
        // Livewire cannot return a file download, so the button must not use
        // wire:click — it has to be a normal anchor.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['organization']);
        $unit->update(['unit_type_id' => 2, 'is_active' => true]);
        $this->actingAs($user);

        $html = (string) $this->get('/units')->assertOk()->getContent();

        $this->assertTrue(
            (bool) preg_match('/<a[^>]+href="'.preg_quote(route('units.export'), '/').'"[^>]*>/', $html),
            'Export control must be an <a href> pointing at the export route.'
        );
    }
}
