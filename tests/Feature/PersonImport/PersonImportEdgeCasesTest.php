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

/**
 * Edge-case coverage for PersonImport: confirmation-pass behaviors
 * (unselected/skip rows, update-on-confirm, unchanged-skip), error
 * recording paths (missing fields, unknown unit, bad reference ids),
 * and integer parsing variants (empty cell, \N marker).
 */
covers(PersonImport::class);

class PersonImportEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Semat $semat;

    private Tahsil $tahsil;

    private Estekhdam $estekhdam;

    private Radif $radif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->unit = Unit::create(['name' => 'واحد تست']);
        $this->semat = Semat::create(['name' => 'تکنسین']);
        $this->tahsil = Tahsil::create(['name' => 'لیسانس']);
        $this->estekhdam = Estekhdam::create(['name' => 'رسمی']);
        $this->radif = Radif::create(['name' => 'ردیف 1']);
    }

    private function header(): string
    {
        return 'n_code	f_name	l_name	t_id	e_id	s_id	r_id	u_id';
    }

    private function csv(array $rows): string
    {
        $content = $this->header()."\n";
        foreach ($rows as $row) {
            $content .= implode("\t", $row)."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'person_import_').'.csv';
        file_put_contents($file, $content);

        return $file;
    }

    private function fullIds(): array
    {
        return [
            $this->tahsil->id,
            $this->estekhdam->id,
            $this->semat->id,
            $this->radif->id,
            $this->unit->id,
        ];
    }

    private function createExistingPerson(string $nCode = '1234567890'): Person
    {
        return Person::create([
            'n_code' => $nCode,
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
            't_id' => $this->tahsil->id,
            'e_id' => $this->estekhdam->id,
            's_id' => $this->semat->id,
            'r_id' => $this->radif->id,
            'u_id' => $this->unit->id,
        ]);
    }

    /**
     * A complete CSV row using valid reference ids.
     */
    private function personRow(string $nCode, string $fName = 'علی', string $lName = 'رضایی'): array
    {
        return [
            $nCode,
            $fName,
            $lName,
            $this->tahsil->id,
            $this->estekhdam->id,
            $this->semat->id,
            $this->radif->id,
            $this->unit->id,
        ];
    }

    /**
     * ایشو #839 (Plan 50): هر تست باید اسکوپ را *صریح* ست کند.
     *
     * پیش از این پلن، اکثر این تست‌ها `setAccessibleUnitIds()` نداشتند و
     * بدون احراز هویت با یک اسکوپ خالیِ ضمنی اجرا می‌شدند — و گاردِ
     * fail-openِ `if (! empty(...))` باعث می‌شد سبز بمانند. کلاس‌ها حالا
     * fail-closed هستند، پس تست باید واحدی را که fixture‌اش در آن است
     * اعلام کند.
     */
    private function scopedImport(): PersonImport
    {
        $import = new PersonImport;
        $import->setAccessibleUnitIds([$this->unit->id]);

        return $import;
    }

    // -----------------------------------------------------------
    //  ایشو #839 (Plan 50): اسکوپ خالی باید fail-closed باشد
    // -----------------------------------------------------------

    /**
     * رگرسیون: `setAccessibleUnitIds([])` + ردیفی که به رکوردِ یک واحد
     * غریبه اشاره می‌کند.
     *
     * پیش از این پلن، گاردِ `if (! empty($this->accessibleUnitIds))` در
     * `loadExistingRecords()` هیچ پیشوندی نمی‌ساخت، ایندکسِ `n_code` شامل
     * **همه‌ی** پرسنل سازمان می‌شد، و `u_id` یک ستون مقایسه‌ای است — پس یک
     * ردیف CSV می‌توانست نامِ یک فردِ خارج از اسکوپ را بازنویسی **کند** یا
     * او را به واحد دیگری **منتقل** کند، و پیش‌نمایش هم مقادیرش را لو می‌داد.
     */
    public function test_empty_scope_never_matches_or_rewrites_a_foreign_person(): void
    {
        $foreignUnit = Unit::create(['name' => 'واحد غریبه']);
        $foreign = Person::create([
            'n_code' => '5555555555',
            'f_name' => 'محرم',
            'l_name' => 'خارجی',
            't_id' => $this->tahsil->id,
            'e_id' => $this->estekhdam->id,
            's_id' => $this->semat->id,
            'r_id' => $this->radif->id,
            'u_id' => $foreignUnit->id,
        ]);

        // Same n_code as the foreign person, new names + a different unit.
        $file = $this->csv([
            ['5555555555', 'دستکاری', 'شده', $this->tahsil->id, $this->estekhdam->id, $this->semat->id, $this->radif->id, $foreignUnit->id],
        ]);

        $import = new PersonImport;
        $import->setAccessibleUnitIds([]);
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);

        // No create, no update — the foreign row is untouched.
        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseHas('persons', [
            'n_code' => '5555555555',
            'f_name' => 'محرم',
            'l_name' => 'خارجی',
            'u_id' => $foreignUnit->id,
        ]);
        $this->assertDatabaseMissing('persons', ['f_name' => 'دستکاری']);

        // The preview must not disclose the foreign record's stored values.
        $encoded = json_encode($results['preview'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('محرم', $encoded);
        $this->assertStringNotContainsString('خارجی', $encoded);

        @unlink($file);
    }

    /**
     * همان سناریو برای ردیفی که واحدش اصلاً در اسکوپ نیست ولی `u_id` معتبر
     * دارد — مسیر «unit not found» باید آن را رد کند.
     */
    public function test_empty_scope_rejects_a_row_whose_unit_is_out_of_scope(): void
    {
        $file = $this->csv([$this->personRow('9876543210')]);

        $import = new PersonImport;
        $import->setAccessibleUnitIds([]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(1, $results['skipped']);
        $this->assertSame(0, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    public function test_preview_pass_without_actions_persists_nothing(): void
    {
        $file = $this->csv([$this->personRow('9876543210')]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Preview pass counts the pending creation but writes nothing.
        $this->assertEquals(1, $results['created']);
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    public function test_confirmation_skips_rows_marked_skip(): void
    {
        $file = $this->csv([$this->personRow('9876543210')]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'skip']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Counters reset for the confirmation pass; skipped rows do nothing.
        $this->assertEquals(0, $results['created']);
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    public function test_confirmation_ignores_unselected_rows(): void
    {
        $row = fn (string $nc, string $pc) => [
            $nc,
            'نام-'.$pc,
            'خانوادگی',
            $this->tahsil->id,
            $this->estekhdam->id,
            $this->semat->id,
            $this->radif->id,
            $this->unit->id,
        ];
        $file = $this->csv([
            $row('9876543210', 'A'),
            $row('9876543211', 'B'),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Only row_2 was confirmed; row_3 must be left untouched.
        $this->assertEquals(1, $results['created']);
        $this->assertDatabaseHas('persons', ['n_code' => '9876543210']);
        $this->assertDatabaseMissing('persons', ['n_code' => '9876543211']);

        @unlink($file);
    }

    public function test_confirmation_updates_existing_person(): void
    {
        $this->createExistingPerson();

        $file = $this->csv([$this->personRow('1234567890', 'اکبر', 'احمدی')]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['updated']);
        $this->assertCount(1, $results['changes']);
        $this->assertArrayHasKey('f_name', $results['changes'][0]['changes']);
        $this->assertArrayHasKey('l_name', $results['changes'][0]['changes']);

        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'اکبر',
            'l_name' => 'احمدی',
        ]);

        @unlink($file);
    }

    public function test_confirmation_counts_identical_row_as_skipped(): void
    {
        $this->createExistingPerson();

        $file = $this->csv([$this->personRow('1234567890', 'احمد', 'محمدی')]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['updated']);
        $this->assertEmpty($results['changes']);
        $this->assertEquals(1, $results['skipped']);

        @unlink($file);
    }

    public function test_confirmation_records_error_for_missing_required_fields(): void
    {
        $file = $this->csv([['', '', '', $this->tahsil->id, $this->estekhdam->id, $this->semat->id, $this->radif->id, $this->unit->id]]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'Missing required fields: n_code, f_name, l_name are required',
            $results['errors'][0]['error']
        );
        $this->assertEquals(1, $results['skipped']);
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    public function test_confirmation_records_error_for_unknown_unit(): void
    {
        $file = $this->csv([['9876543210', 'علی', 'رضایی', $this->tahsil->id, $this->estekhdam->id, $this->semat->id, $this->radif->id, 999999]]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'Unit with id 999999 not found',
            $results['errors'][0]['error']
        );
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    public function test_confirmation_records_error_for_unknown_reference_ids(): void
    {
        // Bad t_id (education level does not exist)
        $file = $this->csv([['9876543210', 'علی', 'رضایی', 999991, $this->estekhdam->id, $this->semat->id, $this->radif->id, $this->unit->id]]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'تحصیلات با شناسه 999991 یافت نشد',
            $results['errors'][0]['error']
        );

        @unlink($file);
    }

    public function test_preview_reports_empty_unit_id(): void
    {
        $file = $this->csv([['9876543210', 'علی', 'رضایی', $this->tahsil->id, $this->estekhdam->id, $this->semat->id, $this->radif->id, '']]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['preview']);
        $this->assertEquals('error', $results['preview'][0]['status']);
        $this->assertEquals('شناسه واحد (u_id) خالی است', $results['preview'][0]['message']);
        $this->assertEquals(1, $results['skipped']);

        @unlink($file);
    }

    public function test_preview_reports_each_unknown_reference_model(): void
    {
        [$tId, $eId, $sId, $rId] = [999991, 999992, 999993, 999994];
        $unitId = $this->unit->id;

        $file = $this->csv([
            ['9876543211', 'الف', 'یک', $tId, $this->estekhdam->id, $this->semat->id, $this->radif->id, $unitId],
            ['9876543212', 'ب', 'دو', $this->tahsil->id, $eId, $this->semat->id, $this->radif->id, $unitId],
            ['9876543213', 'ج', 'سه', $this->tahsil->id, $this->estekhdam->id, $sId, $this->radif->id, $unitId],
            ['9876543214', 'د', 'چهار', $this->tahsil->id, $this->estekhdam->id, $this->semat->id, $rId, $unitId],
        ]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(4, $results['skipped']);
        $messages = array_column($results['preview'], 'message');
        $this->assertContains('تحصیلات با شناسه 999991 یافت نشد', $messages);
        $this->assertContains('نوع استخدام با شناسه 999992 یافت نشد', $messages);
        $this->assertContains('سمت با شناسه 999993 یافت نشد', $messages);
        $this->assertContains('ردیف سازمانی با شناسه 999994 یافت نشد', $messages);

        @unlink($file);
    }

    public function test_integer_parsing_treats_empty_and_null_marker_as_null(): void
    {
        // e_id empty cell and r_id "\N" marker must both parse to null
        // (reference checks skip null ids); row still previews as creatable.
        $file = $this->csv([['9876543210', 'علی', 'رضایی', $this->tahsil->id, '', $this->semat->id, '\\N', $this->unit->id]]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['preview']);
        $this->assertEquals('create', $results['preview'][0]['status']);
        $this->assertNull($results['preview'][0]['data']['e_id']);
        $this->assertNull($results['preview'][0]['data']['r_id']);

        @unlink($file);
    }
}
