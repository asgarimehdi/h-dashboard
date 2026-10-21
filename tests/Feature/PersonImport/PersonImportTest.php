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

covers(PersonImport::class);

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
        // ایشو #839 (Plan 50): اسکوپ صریح — این تست‌ها پیش از این پلن با
        // اسکوپ خالیِ ضمنی اجرا می‌شدند و گاردِ fail-open
        // (`if (! empty(...))`) سبزشان نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
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
        // ایشو #839 (Plan 50): اسکوپ صریح — پیش از این پلن با اسکوپ خالیِ
        // ضمنی اجرا می‌شد و گاردِ fail-open سبزش نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
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
        // ایشو #839 (Plan 50): اسکوپ صریح — پیش از این پلن با اسکوپ خالیِ
        // ضمنی اجرا می‌شد و گاردِ fail-open سبزش نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
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

        // Rejected rows due to organizational scope live in `errors` (#1209).
        $this->assertEquals(0, $results['created']);
        $this->assertCount(1, $results['errors']);
        $this->assertEquals($otherUnit->id, $results['errors'][0]['data']['u_id']);

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
        // ایشو #839 (Plan 50): اسکوپ صریح — پیش از این پلن با اسکوپ خالیِ
        // ضمنی اجرا می‌شد و گاردِ fail-open سبزش نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
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
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "\t\t\t1\t1\t1\t1\t1\n"; // Missing required fields

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        // ایشو #839 (Plan 50): اسکوپ صریح — پیش از این پلن با اسکوپ خالیِ
        // ضمنی اجرا می‌شد و گاردِ fail-open سبزش نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Rejected rows count in the `errors` bucket now (#1209).
        $this->assertEquals(1, count($results['errors']));
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
        // ایشو #839 (Plan 50): اسکوپ صریح — پیش از این پلن با اسکوپ خالیِ
        // ضمنی اجرا می‌شد و گاردِ fail-open سبزش نگه می‌داشت.
        $import->setAccessibleUnitIds([$data['unit']->id]);
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

    // -----------------------------------------------------------
    //  شماره تماس (#738)
    // -----------------------------------------------------------

    public function test_import_reads_the_phone_column_on_create(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\tphone\n";
        $csvContent .= "1112223344\tمریم\tاحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t{$data['unit']->id}\t09121234567\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        // ایشو #839 (Plan 50): اسکوپ صریح.
        $import->setAccessibleUnitIds([$data['unit']->id]);
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $this->assertEquals(1, $import->getImportResults()['created']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1112223344', 'phone' => '09121234567',
        ]);

        @unlink($file);
    }

    public function test_import_updates_an_existing_persons_phone(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\tphone\n";
        $csvContent .= "1234567890\tاحمد\tمحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t{$data['unit']->id}\t02144556677\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        // ایشو #839 (Plan 50): اسکوپ صریح.
        $import->setAccessibleUnitIds([$data['unit']->id]);
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        // Only the phone differs, so it must be the one detected change.
        $changes = $import->getImportResults()['preview'][0]['changes'] ?? [];
        $this->assertSame(['phone'], array_keys($changes));

        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890', 'phone' => '02144556677',
        ]);

        @unlink($file);
    }

    // -----------------------------------------------------------
    //  ایشو #1209: حسابداری کامل ردیف‌ها در پاس confirm
    // -----------------------------------------------------------

    /**
     * شیت دو ردیفی (یک update + یک ردیفی که پیش‌نمایش رد می‌کند): در پاس
     * preview، مجموع سطل‌ها = تعداد ردیف‌های شیت و ردیفِ ردشده در `errors`
     * می‌نشیند، نه `skipped`.
     */
    public function test_preview_counts_a_rejected_row_in_errors_not_skipped(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاکبر\tاحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t999999\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        $import->setAccessibleUnitIds([$data['unit']->id]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(1, count($results['errors']));
        $this->assertSame(3, $results['errors'][0]['row']);
        $this->assertSame('واحد با شناسه 999999 یافت نشد', $results['errors'][0]['error']);

        // وضعیت‌های پیش‌نمایش سناریوی probe: [update, error]
        $this->assertSame(['update', 'error'], array_column($results['preview'], 'status'));
        // قرارداد #۱: مجموع سطل‌ها = تعداد ردیف‌های شیت (۲ ردیف)
        $this->assertSame(2, $results['created'] + $results['updated'] + $results['skipped'] + count($results['errors']));

        @unlink($file);
    }

    /**
     * پاس confirm با action map واقع‌گرایانه (اپراتور برای ردیفِ ردشده هم
     * اکشن انتخاب کرده): ردیفِ ردشده هم شمرده می‌شود (`skipped`) و با
     * **همان پیام** پیش‌نمایش در `errors` نتیجهٔ نهایی می‌ماند.
     */
    public function test_confirm_pass_carries_preview_rejections_into_the_result(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاکبر\tاحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t999999\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        $import->setAccessibleUnitIds([$data['unit']->id]);
        $import->setSelectedActions([
            'row_2' => 'update',
            'row_3' => 'update',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame(2, $results['created'] + $results['updated'] + $results['skipped']);
        $this->assertSame(1, count($results['errors']));
        $this->assertSame(3, $results['errors'][0]['row']);
        $this->assertSame(
            'واحد با شناسه 999999 یافت نشد',
            $results['errors'][0]['error'],
            'پاس confirm باید پیامِ خطای خودِ پیش‌نمایش را نگه دارد.'
        );
        $this->assertDatabaseCount('persons', 1);

        @unlink($file);
    }

    /**
     * پاس confirm با skip کامل: هر دو ردیف در یک سطل می‌نشینند — هیچ ردیفی
     * از نتیجه گم نمی‌شود.
     */
    public function test_confirm_pass_counts_operator_skipped_rows(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاکبر\tاحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";
        $csvContent .= "9876543210\tعلی\tرضایی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t999999\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = new PersonImport;
        $import->setAccessibleUnitIds([$data['unit']->id]);
        $import->setSelectedActions([
            'row_2' => 'skip',
            'row_3' => 'skip',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(2, $results['skipped']);
        $this->assertSame(1, count($results['errors']));
        $this->assertSame(
            'واحد با شناسه 999999 یافت نشد',
            $results['errors'][0]['error']
        );
        $this->assertDatabaseCount('persons', 1);

        @unlink($file);
    }

    /**
     * حالت مرورگر ایشو #1209: ردیف unchanged باید در preview و confirm یک
     * عدد یکسان بدهد — «۱ بدون تغییر» در هر دو.
     */
    public function test_confirm_pass_of_an_unchanged_row_matches_the_preview_count(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tf_name\tl_name\tt_id\te_id\ts_id\tr_id\tu_id\n";
        $csvContent .= "1234567890\tاحمد\tمحمدی\t{$data['tahsil']->id}\t{$data['estekhdam']->id}\t{$data['semat']->id}\t{$data['radif']->id}\t".$data['unit']->id."\n";

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $csvContent);

        $preview = new PersonImport;
        $preview->setAccessibleUnitIds([$data['unit']->id]);
        Excel::import($preview, $file);
        $previewResults = $preview->getImportResults();

        $confirm = new PersonImport;
        $confirm->setAccessibleUnitIds([$data['unit']->id]);
        $confirm->setSelectedActions(['row_2' => 'skip']);
        Excel::import($confirm, $file);
        $confirmResults = $confirm->getImportResults();

        $this->assertSame('unchanged', $previewResults['preview'][0]['status']);
        $this->assertSame($previewResults['skipped'], $confirmResults['skipped']);
        $this->assertSame(1, $confirmResults['skipped']);
        $this->assertSame(0, $confirmResults['created']);
        $this->assertSame(0, $confirmResults['updated']);
        $this->assertSame([], $confirmResults['errors']);
        $this->assertSame(1, $confirmResults['created'] + $confirmResults['updated'] + $confirmResults['skipped']);

        @unlink($file);
    }
}
