<?php

namespace Tests\Feature\HardwareImport;

use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Edge-case coverage for HardwareImport: confirmation-pass behaviors
 * (selected/skip/unselected rows), error recording paths (missing fields,
 * unknown person), mac-based match updates, unchanged-row skipping,
 * clean_at parsing variants and the rules() contract.
 */
class HardwareImportEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private function header(): string
    {
        return implode("\t", [
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ]);
    }

    /**
     * A fully-populated CSV row (\N = the CSV null marker the importer cleans).
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'n_code' => '1234567890',
            'pc_name' => 'PC-NEW',
            'type' => 'pc',
            'os' => 'Windows 11',
            'ip_valid' => '10.1.1.5',
            'ip_local' => '192.168.1.5',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'net_type' => 'LAN',
            'switch' => 'SW-1',
            'port' => '5',
            'shutdown' => '0',
            'vlan' => '10',
            'motherboard' => 'H610',
            'cpu' => 'i7',
            'ram' => '16GB',
            'hdd' => 'SSD 512',
            'comments' => '',
            'mark' => '0',
            'clean_at' => '\N',
        ], $overrides);
    }

    private function writeCsv(array $rows): string
    {
        $content = $this->header()."\n";
        foreach ($rows as $row) {
            $content .= implode("\t", $row)."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'hardware_import_').'.csv';
        file_put_contents($file, $content);

        return $file;
    }

    private function createPerson(string $nCode = '1234567890', ?int $unitId = null): void
    {
        $unitId ??= Unit::create(['name' => 'واحد تست'])->id;
        $this->unit = Unit::find($unitId);

        Person::create([
            'n_code' => $nCode,
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
            't_id' => Tahsil::create(['name' => 'لیسانس'])->id,
            'e_id' => Estekhdam::create(['name' => 'رسمی'])->id,
            's_id' => Semat::create(['name' => 'تکنسین'])->id,
            'r_id' => Radif::create(['name' => 'ردیف 1'])->id,
            'u_id' => $unitId,
        ]);
    }

    public function test_preview_pass_without_actions_persists_nothing(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row()]);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Preview pass counts the pending creation but writes nothing.
        $this->assertEquals(1, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('create', $results['preview'][0]['status']);
        $this->assertDatabaseCount('hardwares', 0);

        @unlink($file);
    }

    public function test_confirmation_with_skip_action_persists_nothing(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row()]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'skip']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Counters were reset for the confirmation pass and the row was skipped.
        $this->assertEquals(0, $results['created']);
        $this->assertDatabaseCount('hardwares', 0);

        @unlink($file);
    }

    public function test_confirmation_ignores_rows_without_a_selected_action(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A']),
            $this->row(['pc_name' => 'PC-B']),
        ]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Only row_2 was confirmed; row_3 has no action and must be left alone.
        $this->assertEquals(1, $results['created']);
        $this->assertDatabaseCount('hardwares', 1);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-A']);

        @unlink($file);
    }

    public function test_confirmation_records_error_for_missing_required_fields(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row(['n_code' => '', 'pc_name' => ''])]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'Missing required fields: n_code and pc_name are required',
            $results['errors'][0]['error']
        );
        $this->assertEquals(2, $results['errors'][0]['row']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertDatabaseCount('hardwares', 0);

        @unlink($file);
    }

    public function test_confirmation_records_error_for_unknown_person(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row(['n_code' => '9999999999'])]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'Person with n_code 9999999999 not found',
            $results['errors'][0]['error']
        );
        $this->assertDatabaseCount('hardwares', 0);

        @unlink($file);
    }

    public function test_confirmation_updates_existing_record_matched_by_mac(): void
    {
        $this->createPerson();

        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-OLD',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'cpu' => 'i5',
            'shutdown' => false,
        ]);

        $file = $this->writeCsv([$this->row([
            'pc_name' => 'PC-OLD',
            'cpu' => 'i9',
            'shutdown' => '1',
        ])]);

        $import = new HardwareImport;
        $import->setCompareKey('mac');
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['updated']);
        $this->assertCount(1, $results['changes']);
        $this->assertEquals('mac', $results['changes'][0]['match_key']);
        $this->assertArrayHasKey('cpu', $results['changes'][0]['changes']);
        $this->assertArrayHasKey('shutdown', $results['changes'][0]['changes']);
        $this->assertEquals('i5', $results['changes'][0]['changes']['cpu']['old']);
        $this->assertEquals('i9', $results['changes'][0]['changes']['cpu']['new']);

        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-OLD',
            'cpu' => 'i9',
            'shutdown' => true,
        ]);

        @unlink($file);
    }

    public function test_confirmation_counts_unchanged_rows_as_skipped(): void
    {
        $this->createPerson();

        // Mirror every field the CSV row maps to so detectChanges finds no diff.
        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-NEW',
            'type' => 'pc',
            'os' => 'Windows 11',
            'ip_valid' => '10.1.1.5',
            'ip_local' => '192.168.1.5',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'net_type' => 'LAN',
            'switch' => 'SW-1',
            'port' => '5',
            'shutdown' => false,
            'vlan' => '10',
            'motherboard' => 'H610',
            'cpu' => 'i7',
            'ram' => '16GB',
            'hdd' => 'SSD 512',
            'comments' => null,
            'mark' => false,
            'clean_at' => null,
        ]);

        $file = $this->writeCsv([$this->row()]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['updated']);
        $this->assertEmpty($results['changes']);
        $this->assertEquals(1, $results['skipped']);

        @unlink($file);
    }

    public function test_clean_at_accepts_iso_date_and_nulls_other_formats(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'clean_at' => '2026-01-05']),
            $this->row(['pc_name' => 'PC-B', 'clean_at' => '05/01/2026']),
        ]);

        $import = new HardwareImport;
        $import->setSelectedActions(['row_2' => 'create', 'row_3' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();
        $this->assertEquals(2, $results['created']);

        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-A', 'clean_at' => '2026-01-05']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-B', 'clean_at' => null]);

        @unlink($file);
    }

    public function test_rules_require_n_code_and_pc_name(): void
    {
        $rules = (new HardwareImport)->rules();

        $this->assertSame('required', $rules['n_code']);
        $this->assertStringContainsString('required', $rules['pc_name']);
        $this->assertStringContainsString('boolean', $rules['shutdown']);
        $this->assertStringContainsString('date_format:Y-m-d', $rules['clean_at']);
        $this->assertCount(19, $rules);
    }
}
