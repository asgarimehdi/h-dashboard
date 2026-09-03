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

covers(HardwareImport::class);

class HardwareImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    protected function createTestData(): array
    {
        // Create unit
        $unit = Unit::create(['name' => 'واحد تست']);

        // Create required reference data
        $semat = Semat::create(['name' => 'تکنسین']);
        $tahsil = Tahsil::create(['name' => 'لیسانس']);
        $estekhdam = Estekhdam::create(['name' => 'رسمی']);
        $radif = Radif::create(['name' => 'ردیف 1']);

        // Create person
        $person = Person::create([
            'n_code' => '1234567890',
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
            'u_id' => $unit->id,
            's_id' => $semat->id,
            't_id' => $tahsil->id,
            'e_id' => $estekhdam->id,
            'r_id' => $radif->id,
        ]);

        // Create existing hardware
        $hardware = Hardware::create([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-001',
            'type' => 'pc',
            'os' => 'Windows 10',
            'cpu' => 'Intel i5',
            'ram' => '8192',
            'hdd' => 'SSD 256GB',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);

        return compact('unit', 'person', 'hardware', 'semat', 'tahsil', 'estekhdam', 'radif');
    }

    public function test_import_preview_creates_new_records(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t\"16384\"\tSSD 512GB\t11:22:33:44:55:66\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Debug output
        error_log('Test results: '.json_encode($results, JSON_PRETTY_PRINT));

        $this->assertEquals(1, $results['created']);
        $this->assertEquals(0, $results['updated']);
        $this->assertEquals(0, $results['skipped']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('create', $results['preview'][0]['status']);

        @unlink($file);
    }

    public function test_import_preview_updates_existing_records(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['created']);
        $this->assertEquals(1, $results['updated']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('update', $results['preview'][0]['status']);
        $this->assertNotEmpty($results['preview'][0]['changes']);

        @unlink($file);
    }

    public function test_import_preview_skips_unchanged_records(): void
    {
        $this->createTestData();

        // Include shutdown and mark columns to match database defaults
        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\tshutdown\tmark\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 10\tIntel i5\t8192\tSSD 256GB\tAA:BB:CC:DD:EE:FF\t1\t0\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['created']);
        $this->assertEquals(0, $results['updated']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('unchanged', $results['preview'][0]['status']);

        @unlink($file);
    }

    public function test_import_respects_organizational_scope(): void
    {
        $data = $this->createTestData();

        // Create another unit and person not accessible
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $otherPerson = Person::create([
            'n_code' => '9876543210',
            'f_name' => 'علی',
            'l_name' => 'رضایی',
            'u_id' => $otherUnit->id,
            's_id' => $data['semat']->id,
            't_id' => $data['tahsil']->id,
            'e_id' => $data['estekhdam']->id,
            'r_id' => $data['radif']->id,
        ]);

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "9876543210\tPC-OTHER\tpc\tWindows 10\tIntel i5\t8192\tSSD 256GB\t22:33:44:55:66:77\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        // Import with only the first unit's accessible IDs
        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$data['unit']->id]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Should be skipped due to organizational scope
        $this->assertEquals(1, $results['skipped']);
        $this->assertEquals(0, $results['created']);

        @unlink($file);
    }

    public function test_import_creates_records_with_confirmation(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-CONFIRM\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t33:44:55:66:77:88\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        $import->setSelectedActions([
            'row_2' => 'create',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['created']);
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-CONFIRM',
            'n_code' => '1234567890',
        ]);

        @unlink($file);
    }

    public function test_import_matches_by_mac_when_pc_name_differs(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-RENAMED\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        $import->setCompareKey('mac');
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Should match by MAC and show as update
        $this->assertEquals(0, $results['created']);
        $this->assertEquals(1, $results['updated']);
        $this->assertEquals('mac', $results['preview'][0]['match_key']);

        @unlink($file);
    }

    public function test_import_handles_validation_errors(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\n";
        $csvContent .= "\t\tpc\tWindows 10\n"; // Missing required fields

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['skipped']);
        $this->assertEquals(0, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('error', $results['preview'][0]['status']);

        @unlink($file);
    }

    public function test_import_detects_changes_correctly(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new HardwareImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['updated']);
        $changes = $results['preview'][0]['changes'] ?? [];

        // Should detect changes in os, cpu, ram, hdd
        $this->assertArrayHasKey('os', $changes);
        $this->assertArrayHasKey('cpu', $changes);
        $this->assertArrayHasKey('ram', $changes);
        $this->assertArrayHasKey('hdd', $changes);
        $this->assertEquals('Windows 10', $changes['os']['old']);
        $this->assertEquals('Windows 11', $changes['os']['new']);

        @unlink($file);
    }
}
