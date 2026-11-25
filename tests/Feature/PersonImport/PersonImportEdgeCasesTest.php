<?php

namespace Tests\Feature\PersonImport;

use App\Imports\PersonImport;
use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Traits\PersianNormalizer;
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

        $this->assertSame(1, count($results['errors']));
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
            'فیلدهای اجباری n_code، f_name و l_name خالی هستند',
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
            'واحد با شناسه 999999 یافت نشد',
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
        // #1209: the rejected row counts in the `errors` bucket.
        $this->assertCount(1, $results['errors']);

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

        // #1209: each rejected row counts in the `errors` bucket.
        $this->assertEquals(4, count($results['errors']));
        $messages = array_column($results['preview'], 'message');
        $this->assertContains('تحصیلات با شناسه 999991 یافت نشد', $messages);
        $this->assertContains('نوع استخدام با شناسه 999992 یافت نشد', $messages);
        $this->assertContains('سمت با شناسه 999993 یافت نشد', $messages);
        $this->assertContains('ردیف سازمانی با شناسه 999994 یافت نشد', $messages);

        @unlink($file);
    }

    /**
     * #1697: an empty lookup cell maps to null, but `t_id`/`e_id`/`r_id`/`s_id`
     * are NOT NULL on `persons` — writing null aborts the whole sheet with
     * 23502, so the preview must reject the row instead of offering it as
     * creatable.
     */
    public function test_empty_lookup_cells_map_to_null_and_are_rejected(): void
    {
        // e_id empty cell and r_id "\N" marker must both parse to null.
        $file = $this->csv([['9876543210', 'علی', 'رضایی', $this->tahsil->id, '', $this->semat->id, '\\N', $this->unit->id]]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['preview']);
        $this->assertEquals('error', $results['preview'][0]['status']);
        $this->assertNull($results['preview'][0]['data']['e_id']);
        $this->assertNull($results['preview'][0]['data']['r_id']);
        $this->assertSame(
            'شناسه نوع استخدام (e_id) خالی است؛ شناسه ردیف سازمانی (r_id) خالی است',
            $results['preview'][0]['message']
        );

        @unlink($file);
    }

    // -----------------------------------------------------------
    //  ایشو #1697: شناسه‌های lookup غیرعددی نباید به ۰ تبدیل شوند
    // -----------------------------------------------------------

    public function test_preview_rejects_a_non_numeric_lookup_id(): void
    {
        $bad = $this->personRow('9876543210');
        $bad[3] = 'abc';

        $file = $this->csv([$bad, $this->personRow('9876543211')]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(2, $results['preview']);
        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertNull($results['preview'][0]['data']['t_id']);
        $this->assertSame(
            'شناسه تحصیلات (t_id) باید یک عدد صحیح باشد؛ مقدار سلول: abc',
            $results['preview'][0]['message']
        );
        $this->assertCount(1, $results['errors']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame('create', $results['preview'][1]['status']);
        // Sheet order and row numbering must survive the rejection.
        $this->assertSame([2, 3], array_column($results['preview'], 'row'));

        @unlink($file);
    }

    public function test_confirm_writes_the_good_rows_when_one_lookup_id_is_non_numeric(): void
    {
        $bad = $this->personRow('9876543210');
        $bad[3] = 'abc';

        $file = $this->csv([$bad, $this->personRow('9876543211')]);

        $import = $this->scopedImport();
        // The operator confirms the rejected row too: #1209 keeps it skipped.
        $import->setSelectedActions(['row_2' => 'create', 'row_3' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(1, $results['created']);
        $this->assertSame(1, $results['skipped']);
        $this->assertCount(1, $results['errors']);
        $this->assertSame(2, $results['errors'][0]['row']);
        $this->assertSame(
            'شناسه تحصیلات (t_id) باید یک عدد صحیح باشد؛ مقدار سلول: abc',
            $results['errors'][0]['error']
        );
        $this->assertDatabaseHas('persons', ['n_code' => '9876543211', 't_id' => $this->tahsil->id]);
        $this->assertDatabaseMissing('persons', ['n_code' => '9876543210']);
        $this->assertDatabaseCount('persons', 1);

        @unlink($file);
    }

    public function test_fractional_lookup_id_is_rejected_not_truncated(): void
    {
        $row = $this->personRow('9876543210');
        $row[3] = '12.9';

        $file = $this->csv([$row]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertNull($results['preview'][0]['data']['t_id']);
        $this->assertStringContainsString('12.9', $results['preview'][0]['message']);
        // No silent truncation to 12 and a misleading "not found" message.
        $this->assertStringNotContainsString('با شناسه 12 ', $results['preview'][0]['message']);

        @unlink($file);
    }

    public function test_whole_float_lookup_cell_is_accepted(): void
    {
        $row = $this->personRow('9876543210');
        $row[3] = $this->tahsil->id.'.0';

        $file = $this->csv([$row]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('create', $results['preview'][0]['status']);
        $this->assertSame($this->tahsil->id, $results['preview'][0]['data']['t_id']);

        @unlink($file);
    }

    public function test_persian_digit_lookup_id_is_normalized(): void
    {
        $row = $this->personRow('9876543210');
        $row[3] = strtr((string) $this->tahsil->id, array_flip(PersianNormalizer::digitMap()));

        $file = $this->csv([$row]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('create', $results['preview'][0]['status']);
        $this->assertSame($this->tahsil->id, $results['preview'][0]['data']['t_id']);

        @unlink($file);
    }

    public function test_zero_and_negative_lookup_ids_are_rejected(): void
    {
        $zeroTId = $this->personRow('9876543210');
        $zeroTId[3] = '0';

        $negativeSId = $this->personRow('9876543211');
        $negativeSId[5] = '-1';

        $file = $this->csv([$zeroTId, $negativeSId]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertSame('error', $results['preview'][1]['status']);
        $this->assertStringContainsString('مقدار سلول: 0', $results['preview'][0]['message']);
        $this->assertStringContainsString('مقدار سلول: -1', $results['preview'][1]['message']);
        $this->assertSame(0, $results['created']);
        $this->assertDatabaseCount('persons', 0);

        @unlink($file);
    }

    // -----------------------------------------------------------
    //  ایشو #1769: آینهٔ #1758 — پیش‌نمایش باید همان چیزی را مقایسه کند
    //  که نوشته می‌شود (ي/ك/أ و ارقام فارسی)
    // -----------------------------------------------------------

    public function test_a_name_that_differs_only_in_persian_variants_is_not_a_change(): void
    {
        $this->createExistingPerson(); // احمد / محمدی (هوک saving آن‌ها را folded ذخیره کرده)

        // أ = U+0623 و ي = U+064A؛ هر دو در normalizeForSearch به ا/ی تبدیل می‌شوند.
        $file = $this->csv([$this->personRow('1234567890', 'أحمد', 'محمدي')]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('unchanged', $results['preview'][0]['status']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertArrayNotHasKey('changes', $results['preview'][0]);
        $this->assertSame(1, $results['created'] + $results['updated'] + $results['skipped']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
        ]);

        @unlink($file);
    }

    public function test_confirming_a_variant_only_row_writes_nothing(): void
    {
        $this->createExistingPerson(); // احمد / محمدی

        $file = $this->csv([$this->personRow('1234567890', 'احمد', 'محمدي')]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame([], $results['changes']);
        $this->assertSame([], $results['errors']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
        ]);

        @unlink($file);
    }

    public function test_a_genuinely_different_name_still_updates_and_reimport_is_stable(): void
    {
        $this->createExistingPerson(); // احمد / محمدی

        // أ→ا و ي→ی: تغییر واقعی است، و `new` در پیش‌نمایش باید folded باشد.
        $file = $this->csv([$this->personRow('1234567890', 'أکبر', 'رضايي')]);

        $preview = $this->scopedImport();
        Excel::import($preview, $file);
        $results = $preview->getImportResults();

        $this->assertSame('update', $results['preview'][0]['status']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(['f_name', 'l_name'], array_keys($results['preview'][0]['changes']));
        $this->assertSame(['old' => 'احمد', 'new' => 'اکبر'], $results['preview'][0]['changes']['f_name']);
        $this->assertSame(['old' => 'محمدی', 'new' => 'رضایی'], $results['preview'][0]['changes']['l_name']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertSame(1, $confirm->getImportResults()['updated']);
        $this->assertDatabaseHas('persons', [
            'n_code' => '1234567890',
            'f_name' => 'اکبر',
            'l_name' => 'رضایی',
        ]);

        // round-trip: write با همان تابعی fold کرد که مقایسه fold می‌کند
        $again = $this->scopedImport();
        Excel::import($again, $file);
        $againResults = $again->getImportResults();

        $this->assertSame(0, $againResults['updated']);
        $this->assertSame('unchanged', $againResults['preview'][0]['status']);

        @unlink($file);
    }
}
