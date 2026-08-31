<?php

namespace Tests\Feature\PersonImport;

use App\Imports\PersonImport;
use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

covers(\App\Imports\PersonImport::class);

class PersonImportTest extends TestCase
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

        return compact('unit', 'person', 'semat', 'tahsil', 'estekhdam', 'radif');
    }

    public function test_import_preview_creates_new_records(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['created']);
        $this->assertEquals(0, $results['updated']);
        $this->assertEquals(0, $results['skipped']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('create', $results['preview'][0]['status']);

        @unlink($file);
    }

    public function test_import_preview_updates_existing_records(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاحمد\tاحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
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
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاحمد\tمحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
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

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t1\t1\t1\t1\t".$otherUnit->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        // Import with only the first unit's accessible IDs
        $import = new PersonImport;
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
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        $import->setSelectedActions([
            'row_2' => 'create',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['created']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '9876543210',
            'f_name' => 'علی',
            'l_name' => 'رضایی',
        ]);

        @unlink($file);
    }

    public function test_import_handles_validation_errors(): void
    {
        $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "\t\t\t1\t1\t1\t1\t1\n"; // Missing required fields

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
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
        $data = $this->createTestData();

        // Create additional reference records with different IDs
        $tahsil2 = Tahsil::create(['name' => 'فوق لیسانس']);
        $estekhdam2 = Estekhdam::create(['name' => 'قراردادی']);
        $semat2 = Semat::create(['name' => 'تخصصی']);
        $radif2 = Radif::create(['name' => 'ردیف 2']);

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاکبر\tاحمدی\t{$tahsil2->id}\t{$estekhdam2->id}\t{$semat2->id}\t{$radif2->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Debug output
        error_log('Test results: '.json_encode($results, JSON_PRETTY_PRINT));

        $this->assertEquals(1, $results['updated']);
        $changes = $results['preview'][0]['changes'] ?? [];

        // Should detect changes in f_name, l_name, t_id, e_id, s_id, r_id
        $this->assertArrayHasKey('f_name', $changes);
        $this->assertArrayHasKey('l_name', $changes);
        $this->assertArrayHasKey('t_id', $changes);
        $this->assertArrayHasKey('e_id', $changes);
        $this->assertArrayHasKey('s_id', $changes);
        $this->assertArrayHasKey('r_id', $changes);
        $this->assertEquals('محمدی', $changes['l_name']['old']);
        $this->assertEquals('احمدی', $changes['l_name']['new']);

        @unlink($file);
    }
}
