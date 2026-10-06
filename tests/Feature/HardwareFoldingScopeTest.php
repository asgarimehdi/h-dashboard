<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #815 — Person/Hardware hook-normalizes its own fields on save, but
 * Unit/Semat/Radif/Tahsil/Estekhdam have no such hook, so a lookup name keeps
 * Arabic code points and the term-only normalization makes it unreachable to
 * its own filter. Scopes and exports must fold the column.
 */
class HardwareFoldingScopeTest extends TestCase
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

    protected function makeHardwareInUnit(Unit $unit, string $pcName): Hardware
    {
        $person = Person::factory()->create(['u_id' => $unit->id]);
        $sId = DB::table('semats')->first()->id;

        return Hardware::factory()->create([
            'n_code' => $person->n_code,
            'pc_name' => $pcName,
        ]);
    }

    #[Test]
    public function filter_unit_finds_a_unit_named_with_arabic_alef(): void
    {
        $unit = Unit::factory()->create(['name' => 'مرکز حسین آباد']);
        $this->makeHardwareInUnit($unit, 'AlefUnitPC');

        // 'ا' (U+0627) in the term must match 'آ' (U+0622) in the stored name.
        $this->assertGreaterThan(
            0,
            Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')->select('hardwares.*')->filterUnit('مرکز حسین آباد')->count(),
            'filterUnit must find the unit by its own Arabic-Alef name'
        );
    }

    #[Test]
    public function filter_unit_finds_a_unit_named_with_arabic_yeh_kaf(): void
    {
        $unit = Unit::factory()->create(['name' => 'کتابخانه مرکزی']);
        $this->makeHardwareInUnit($unit, 'YehUnitPC');

        $this->assertGreaterThan(
            0,
            Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')->select('hardwares.*')->filterUnit('کتابخانه مرکزی')->count()
        );
    }

    #[Test]
    public function filter_semat_finds_a_semat_named_with_arabic_alef(): void
    {
        DB::table('semats')->insert(['name' => 'رئیس آموزش']);
        $sematId = DB::table('semats')->where('name', 'رئیس آموزش')->value('id');
        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id, 's_id' => $sematId]);
        Hardware::factory()->create(['n_code' => $person->n_code, 'pc_name' => 'SematPC']);

        $this->assertGreaterThan(
            0,
            Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')->select('hardwares.*')->filterSemat('رئیس آموزش')->count()
        );
    }

    #[Test]
    public function hardware_export_matches_scope_counts_for_alef_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        $unit->update(['name' => 'مرکز حسین آباد']);
        $person = Person::where('u_id', $unit->id)->first();
        Hardware::factory()->create(['n_code' => $person->n_code, 'pc_name' => 'ExportAlefPC']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $exportRows = $this->exportRows(['unit' => 'مرکز حسین آباد']);

        $scopeCount = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')->select('hardwares.*')->filterUnit('مرکز حسین آباد')->count();

        $this->assertSame($scopeCount, count($exportRows));
        $this->assertNotEmpty($exportRows);
    }

    #[Test]
    public function hardware_export_matches_scope_counts_for_arabic_semat(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_hardware']);
        DB::table('semats')->insert(['name' => 'رئیس آموزش']);
        $sematId = DB::table('semats')->where('name', 'رئیس آموزش')->value('id');
        $person = Person::where('u_id', $unit->id)->first();
        $person->update(['s_id' => $sematId]);
        Hardware::factory()->create(['n_code' => $person->n_code, 'pc_name' => 'ExportSematPC']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $exportRows = $this->exportRows(['semat' => 'رئیس آموزش']);

        $this->assertNotEmpty($exportRows);
        $this->assertSame(
            Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')->select('hardwares.*')->filterSemat('رئیس آموزش')->count(),
            count($exportRows)
        );
    }

    #[Test]
    public function persons_export_matches_list_filter_for_alef_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['kargozini']);
        $unit->update(['name' => 'مرکز حسین آباد']);
        $this->actingAs($user);
        Session::put('current_unit_id', $unit->id);

        $response = $this->get(route('kargozini.persons.export', ['search' => 'حسین آباد']));
        $response->assertOk()->assertDownload();

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        array_shift($rows);
        $spreadsheet->disconnectWorksheets();

        $this->assertNotEmpty($rows, 'Persons export unit-name search must find the Arabic-Alef unit');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function exportRows(array $query): array
    {
        $response = $this->get(route('hardware.export', array_merge(['columns' => 'n_code,pc_name'], $query)));
        $response->assertOk()->assertDownload();

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        array_shift($rows);
        $spreadsheet->disconnectWorksheets();

        return array_values($rows);
    }
}
