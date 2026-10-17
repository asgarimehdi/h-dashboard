<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1050 — the hardware filters were implemented three times (model
 * `scopeFilter*`, `HardwareExportController` inline, Livewire
 * `hardwares()` inline) and had already drifted.
 *
 * This test pins all three surfaces to the SAME result set across a filter
 * matrix, including the two documented divergences:
 *
 *   - `shutdown` / `mark` accepted `'true'` in the model but only `'1'` in the
 *     export and Livewire copies;
 *   - the general `search` lacked the full-name `CONCAT` on the model copy
 *     (so "زهرا رضایی" found nothing there but matched in the other two).
 *
 * Before the fix the parity assertions below are red for exactly those cases;
 * after it, every surface returns the same rows.
 */
class HardwareFilterParityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected User $user;

    /** @var list<int> */
    protected array $accessibleIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->user = $this->createUserWithUnit(['manage_hardware'])['user'];

        $unitA = Unit::factory()->create(['name' => 'مرکز حسین آباد']);   // Arabic alef
        // A CHILD of unitA: with `current_unit_id` set, the accessible scope is
        // that unit plus its active descendants, so both rows below are in scope
        // on every surface (a sibling unit would only be reachable via `units()`).
        $unitB = Unit::factory()->create(['name' => 'واحد شمال', 'parent_id' => $unitA->id]);

        $sematA = DB::table('semats')->insertGetId(['name' => 'رئیس آموزش']); // Arabic alef
        $sematB = DB::table('semats')->insertGetId(['name' => 'کارشناس']);
        $this->resyncSequence('semats');

        $personA = Person::factory()->create([
            'u_id' => $unitA->id, 'f_name' => 'زهرا', 'l_name' => 'رضایی', 's_id' => $sematA,
        ]);
        $personB = Person::factory()->create([
            'u_id' => $unitB->id, 'f_name' => 'سارا', 'l_name' => 'مرادی', 's_id' => $sematB,
        ]);

        Hardware::factory()->create([
            'n_code' => $personA->n_code,
            'pc_name' => 'PC-A',
            'type' => 'laptop',
            'shutdown' => true,
            'mark' => true,
        ]);
        Hardware::factory()->create([
            'n_code' => $personB->n_code,
            'pc_name' => 'PC-B',
            'type' => 'pc',
            'shutdown' => false,
            'mark' => false,
        ]);

        $this->actingAs($this->user);
        Session::put('current_unit_id', $unitA->id);

        // Mirror exactly what every surface resolves for org scope.
        $this->accessibleIds = app(AccessService::class)->accessibleUnitIds($this->user);
        $this->assertSame(
            collect([$unitA->id, $unitB->id])->sort()->values()->all(),
            collect($this->accessibleIds)->sort()->values()->all(),
            'fixture requires both units to be in the acting user\'s scope'
        );
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string,4:list<string>}>
     */
    public static function filterCases(): array
    {
        return [
            // [model scope, Livewire property, export query param, value, expected pc_names]
            'search — last-name term' => ['filterSearch', 'search', 'search', 'رضایی', ['PC-A']],
            'search — full person name (CONCAT)' => ['filterSearch', 'search', 'search', 'زهرا رضایی', ['PC-A']],
            'type — literal' => ['filterType', 'filterType', 'type', 'laptop', ['PC-A']],
            'type — alias desktop→pc' => ['filterType', 'filterType', 'type', 'desktop', ['PC-B']],
            'shutdown — "1"' => ['filterShutdown', 'filterShutdown', 'shutdown', '1', ['PC-A']],
            'shutdown — "true"' => ['filterShutdown', 'filterShutdown', 'shutdown', 'true', ['PC-A']],
            'mark — "1"' => ['filterMark', 'filterMark', 'mark', '1', ['PC-A']],
            'mark — "true"' => ['filterMark', 'filterMark', 'mark', 'true', ['PC-A']],
            'person — last name' => ['filterPerson', 'filterPerson', 'person', 'رضایی', ['PC-A']],
            'person — full name (CONCAT)' => ['filterPerson', 'filterPerson', 'person', 'زهرا رضایی', ['PC-A']],
            'unit — Arabic alef name' => ['filterUnit', 'filterUnit', 'unit', 'مرکز حسین آباد', ['PC-A']],
            'semat — Arabic alef name' => ['filterSemat', 'filterSemat', 'semat', 'رئیس آموزش', ['PC-A']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('filterCases')]
    public function the_three_filter_surfaces_return_the_same_rows(
        string $modelScope,
        string $livewireProp,
        string $exportParam,
        string $value,
        array $expected,
    ): void {
        sort($expected);

        $model = $this->modelPcNames($modelScope, $value);
        $livewire = $this->livewirePcNames($livewireProp, $value);
        $export = $this->exportPcNames([$exportParam => $value]);

        $this->assertSame($expected, $model, "model scope {$modelScope}('{$value}') drifted");
        $this->assertSame($expected, $livewire, "Livewire {$livewireProp}('{$value}') drifted");
        $this->assertSame($expected, $export, "export {$exportParam}('{$value}') drifted");
    }

    /**
     * Model-scope surface, queried the same way the API controller does
     * (persons joined, org scope applied).
     *
     * @return list<string>
     */
    private function modelPcNames(string $scope, string $value): array
    {
        $query = Hardware::join('persons', 'hardwares.n_code', '=', 'persons.n_code')
            ->whereIn('persons.u_id', $this->accessibleIds)
            ->select('hardwares.*');

        $query->{$scope}($value);

        $names = $query->pluck('pc_name')->all();
        sort($names);

        return $names;
    }

    /**
     * Livewire surface — read the paginated rows the component actually renders.
     *
     * @return list<string>
     */
    private function livewirePcNames(string $prop, string $value): array
    {
        $component = Livewire::actingAs($this->user)
            ->test('hardware.index')
            ->set($prop, $value);

        $names = $component->instance()->hardwares()->pluck('pc_name')->all();
        sort($names);

        return $names;
    }

    /**
     * Export surface — download the spreadsheet and read the pc_name column.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function exportPcNames(array $filters): array
    {
        $response = $this->get(route('hardware.export', array_merge(['columns' => 'n_code,pc_name'], $filters)));
        $response->assertOk()->assertDownload();

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        $spreadsheet->disconnectWorksheets();

        $headings = array_shift($rows);
        $column = array_search('نام دستگاه', $headings, true);
        $this->assertNotFalse($column, 'the export must contain a pc_name column');

        $names = array_values(array_filter(array_map(
            fn (array $row): ?string => $row[$column] ?? null,
            $rows
        )));
        sort($names);

        return $names;
    }
}
