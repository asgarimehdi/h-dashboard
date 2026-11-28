<?php

namespace Tests\Feature\HardwareImport;

use App\Exports\HardwareExport;
use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Support\HardwareAuditChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Edge-case coverage for HardwareImport: confirmation-pass behaviors
 * (selected/skip/unselected rows), error recording paths (missing fields,
 * unknown person), mac-based match updates, unchanged-row skipping,
 * clean_at parsing variants and the rules() contract.
 */
covers(HardwareImport::class);

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

    /**
     * ایشو #839 (Plan 50): هر تست باید اسکوپ را *صریح* ست کند — پیش از این
     * پلن این تست‌ها با اسکوپ خالیِ ضمنی اجرا می‌شدند و گاردِ fail-openِ
     * `if (! empty(...))` نگهشان می‌داشت. واحد از `createPerson()` در
     * `$this->unit` نگه داشته می‌شود.
     */
    private function scopedImport(): HardwareImport
    {
        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$this->unit->id]);

        return $import;
    }

    /**
     * #1758: seed exactly what `row()` writes, so the only reported diff is the
     * field a test deliberately overrides.
     */
    private function seedHardware(array $overrides = []): Hardware
    {
        return Hardware::create(array_merge([
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
        ], $overrides));
    }

    // -----------------------------------------------------------
    //  ایشو #1758: rename روی match با MAC باید در پیش‌نمایش دیده شود
    // -----------------------------------------------------------

    public function test_preview_shows_the_pc_name_rename_of_a_mac_matched_device(): void
    {
        $this->createPerson();
        $this->seedHardware(['pc_name' => 'PC-OLD', 'cpu' => 'i5']);

        $file = $this->writeCsv([$this->row(['pc_name' => 'PC-NEW', 'cpu' => 'i9'])]);

        $import = $this->scopedImport();
        $import->setCompareKey('mac');
        Excel::import($import, $file);

        $results = $import->getImportResults();
        $row = $results['preview'][0];

        $this->assertSame('update', $row['status']);
        $this->assertSame('mac', $row['match_key']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(['pc_name', 'cpu'], array_keys($row['changes']));
        $this->assertSame(['old' => 'PC-OLD', 'new' => 'PC-NEW'], $row['changes']['pc_name']);

        @unlink($file);
    }

    public function test_confirmed_mac_match_rename_is_shown_in_the_preview_and_written_to_the_audit(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['pc_name' => 'PC-OLD', 'cpu' => 'i5']);

        $file = $this->writeCsv([$this->row(['pc_name' => 'PC-NEW', 'cpu' => 'i9'])]);

        $import = $this->scopedImport();
        $import->setCompareKey('mac');
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(1, $results['updated']);
        $this->assertSame('mac', $results['changes'][0]['match_key']);
        $this->assertSame('PC-OLD', $results['changes'][0]['changes']['pc_name']['old']);
        $this->assertSame('PC-NEW', $results['changes'][0]['changes']['pc_name']['new']);

        $this->assertDatabaseHas('hardwares', ['id' => $hardware->id, 'pc_name' => 'PC-NEW', 'cpu' => 'i9']);

        $audit = HardwareAudit::where('source', 'import')->where('action', 'updated')->first();
        $this->assertNotNull($audit);

        $changes = is_array($audit->changes) ? $audit->changes : json_decode((string) $audit->changes, true);
        $pcNameChange = collect($changes)->firstWhere('field', 'pc_name');
        $this->assertSame('pc_name', $pcNameChange['field']);
        $this->assertSame('PC-OLD', $pcNameChange['old']);
        $this->assertSame('PC-NEW', $pcNameChange['new']);

        @unlink($file);
    }

    public function test_a_row_that_only_renames_a_mac_matched_device_is_an_update_not_a_silent_no_op(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['pc_name' => 'PC-OLD']);

        $file = $this->writeCsv([$this->row(['pc_name' => 'PC-NEW'])]);

        $preview = $this->scopedImport();
        $preview->setCompareKey('mac');
        Excel::import($preview, $file);

        $results = $preview->getImportResults();
        $this->assertSame('update', $results['preview'][0]['status']);
        $this->assertSame(['pc_name'], array_keys($results['preview'][0]['changes']));
        $this->assertSame(1, $results['updated']);
        $this->assertSame(0, $results['skipped']);

        $confirm = $this->scopedImport();
        $confirm->setCompareKey('mac');
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertSame('PC-NEW', $hardware->fresh()->pc_name);
        $this->assertSame(1, HardwareAudit::where('action', 'updated')->count());

        @unlink($file);
    }

    public function test_a_pc_name_that_differs_only_in_persian_variants_is_not_a_change(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['pc_name' => "PC\u{06CC}"]); // ی فارسی

        $file = $this->writeCsv([$this->row(['pc_name' => "PC\u{064A}"])]); // ي عربی

        $import = $this->scopedImport();
        $import->setCompareKey('both'); // pc_name خام match نمی‌شود؛ mac match می‌کند
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('unchanged', $results['preview'][0]['status']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame("PC\u{06CC}", $hardware->fresh()->pc_name);
        $this->assertSame(0, HardwareAudit::where('source', 'import')->count());

        @unlink($file);
    }

    // -----------------------------------------------------------
    //  ایشو #1829: ردیف split-match (pc_name → A، mac → B) باید رد شود
    // -----------------------------------------------------------

    public function test_a_row_whose_pc_name_and_mac_point_at_two_devices_is_rejected(): void
    {
        $this->createPerson();
        $a = $this->seedHardware(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:01', 'cpu' => 'i5']);
        $b = $this->seedHardware(['pc_name' => 'PC-B', 'mac' => 'AA:BB:CC:00:00:02', 'cpu' => 'i5']);

        // تنها تفاوت این ردیف با A همان mac است، و آن mac مال B است.
        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:02', 'cpu' => 'i5']),
        ]);

        $preview = $this->scopedImport();
        $preview->setCompareKey('both');
        Excel::import($preview, $file);

        $results = $preview->getImportResults();
        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertArrayNotHasKey('id', $results['preview'][0]);
        $this->assertSame(0, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(0, $results['skipped']);
        $this->assertCount(1, $results['errors']);
        $this->assertSame(2, $results['errors'][0]['row']);
        $this->assertStringContainsString('PC-A', $results['errors'][0]['error']);
        $this->assertStringContainsString('PC-B', $results['errors'][0]['error']);
        $previewMessage = $results['errors'][0]['error'];

        // عمداً action انتخاب می‌کنیم تا گارد #1209 ثابت شود.
        $confirm = $this->scopedImport();
        $confirm->setCompareKey('both');
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $results = $confirm->getImportResults();
        $this->assertSame(0, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertSame($previewMessage, $results['errors'][0]['error']);

        $this->assertSame('PC-A', $a->fresh()->pc_name);
        $this->assertSame('AA:BB:CC:00:00:01', $a->fresh()->mac);
        $this->assertSame('PC-B', $b->fresh()->pc_name);
        $this->assertSame('AA:BB:CC:00:00:02', $b->fresh()->mac);
        $this->assertSame(2, Hardware::count());
        $this->assertSame(0, HardwareAudit::where('action', 'updated')->count());

        @unlink($file);
    }

    public function test_unambiguous_both_key_matches_are_unchanged(): void
    {
        $this->createPerson();
        $a = $this->seedHardware(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:01', 'cpu' => 'i5']);
        $b = $this->seedHardware(['pc_name' => 'PC-B', 'mac' => 'AA:BB:CC:00:00:02', 'cpu' => 'i5']);

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:01', 'cpu' => 'i5']),
            $this->row(['pc_name' => 'PC-B', 'mac' => 'AA:BB:CC:00:00:02', 'cpu' => 'i5']),
            $this->row(['pc_name' => 'PC-NEW-1', 'mac' => 'AA:BB:CC:00:00:02', 'cpu' => 'i5']),
            $this->row(['pc_name' => 'PC-NEW-2', 'mac' => 'AA:BB:CC:00:00:01', 'cpu' => 'i5']),
        ]);

        $preview = $this->scopedImport();
        $preview->setCompareKey('both');
        Excel::import($preview, $file);

        $rows = $preview->getImportResults()['preview'];

        // هر دو کلید روی یک رکورد → تعارض فعال نمی‌شود.
        $this->assertSame('unchanged', $rows[0]['status']);
        $this->assertSame('pc_name', $rows[0]['match_key']);
        $this->assertSame($a->id, $rows[0]['id']);

        $this->assertSame('unchanged', $rows[1]['status']);
        $this->assertSame('pc_name', $rows[1]['match_key']);
        $this->assertSame($b->id, $rows[1]['id']);

        // تک‌کلید mac + rename → مرز #1758 دست‌نخورده.
        $this->assertSame('update', $rows[2]['status']);
        $this->assertSame('mac', $rows[2]['match_key']);
        $this->assertSame($b->id, $rows[2]['id']);
        $this->assertSame(['pc_name'], array_keys($rows[2]['changes']));

        $this->assertSame('update', $rows[3]['status']);
        $this->assertSame('mac', $rows[3]['match_key']);
        $this->assertSame($a->id, $rows[3]['id']);
        $this->assertSame(['pc_name'], array_keys($rows[3]['changes']));

        $confirm = $this->scopedImport();
        $confirm->setCompareKey('both');
        $confirm->setSelectedActions([
            'row_2' => 'update',
            'row_3' => 'update',
            'row_4' => 'update',
            'row_5' => 'update',
        ]);
        Excel::import($confirm, $file);

        $results = $confirm->getImportResults();
        $this->assertSame(0, $results['created']);
        $this->assertSame(2, $results['updated']);
        $this->assertSame(2, $results['skipped']);
        $this->assertSame(4, $results['created'] + $results['updated'] + $results['skipped']);

        $this->assertSame('PC-NEW-2', $a->fresh()->pc_name);
        $this->assertSame('AA:BB:CC:00:00:01', $a->fresh()->mac);
        $this->assertSame('PC-NEW-1', $b->fresh()->pc_name);
        $this->assertSame('AA:BB:CC:00:00:02', $b->fresh()->mac);
        $this->assertSame(2, Hardware::count());
        $this->assertSame(2, HardwareAudit::where('action', 'updated')->count());

        @unlink($file);
    }

    public function test_detect_changes_compares_exactly_the_columns_the_upsert_writes(): void
    {
        $import = new HardwareImport;
        $reflection = new \ReflectionClass($import);

        $updateColumns = $reflection->getMethod('updateColumns');
        $updateColumns->setAccessible(true);
        $writeColumns = $updateColumns->invoke($import);

        // 1) لیست نوشتنی را freeze می‌کند: حذف/اضافهٔ هر ستون (از جمله pc_name) باید آگاهانه باشد.
        $this->assertSame([
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ], $writeColumns);

        // 2) هر ستون نوشتنی باید audit شود.
        $this->assertEqualsCanonicalizing($writeColumns, HardwareAuditChange::AUDITED_FIELDS);

        // 3) detectChanges باید دقیقاً همین ستون‌ها را مقایسه کند (دریفت #1758).
        // `clean_at` جدا ست می‌شود چون cast تاریخ هنگام set مقدار غیرتاریخ را
        // رد می‌کند؛ در غیر این صورت خودِ ساخت مدل throw می‌کند.
        $existing = new Hardware(array_fill_keys(array_diff($writeColumns, ['clean_at']), 'OLD'));
        $existing->clean_at = null;
        $existing->shutdown = false;
        $existing->mark = false;

        $newData = [];
        foreach ($writeColumns as $field) {
            $newData[$field] = 'NEW-'.$field;
        }
        $newData['clean_at'] = '2030-01-01';
        $newData['shutdown'] = true;
        $newData['mark'] = true;

        $detectChanges = $reflection->getMethod('detectChanges');
        $detectChanges->setAccessible(true);
        $changes = $detectChanges->invoke($import, $existing, $newData);

        $this->assertEqualsCanonicalizing($writeColumns, array_keys($changes));
    }

    // -----------------------------------------------------------
    //  ایشو #839 (Plan 50): اسکوپ خالی باید fail-closed باشد
    // -----------------------------------------------------------

    /**
     * رگرسیون: `setAccessibleUnitIds([])` + ردیفی که با `mac` یک رکوردِ
     * سخت‌افزاری متعلق به پرسنلِ واحدِ غریبه تطبیق می‌کند.
     *
     * پیش از این پلن، گاردِ `if (! empty($this->accessibleUnitIds))` در
     * `loadExistingRecords()`/`loadExistingPersons()` هیچ پیشوندی نمی‌ساخت؛
     * ایندکسِ `mac`/`pc_name` شامل **همه‌ی** سخت‌افزارهای سازمان می‌شد و
     * یک ردیف CSV می‌توانست رکوردِ غریبه را بازنویسی کند، ضمن اینکه پیش‌نمایش
     * مقادیرش را لو می‌داد.
     */
    public function test_empty_scope_never_matches_or_rewrites_foreign_hardware(): void
    {
        $foreignUnit = Unit::create(['name' => 'واحد غریبه']);
        $this->createPerson('1234567890', $foreignUnit->id);

        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-FOREIGN',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'cpu' => 'i5',
            'shutdown' => false,
        ]);

        // Same mac as the foreign row — must NOT be matched under an empty scope.
        $file = $this->writeCsv([$this->row([
            'pc_name' => 'PC-RENAMED',
            'cpu' => 'i9',
            'shutdown' => '1',
        ])]);

        $import = new HardwareImport;
        $import->setAccessibleUnitIds([]);
        $import->setCompareKey('mac');
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);

        // No create, no update — the foreign row is untouched.
        $this->assertDatabaseCount('hardwares', 1);
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-FOREIGN',
            'cpu' => 'i5',
            'shutdown' => false,
        ]);
        $this->assertDatabaseMissing('hardwares', ['pc_name' => 'PC-RENAMED']);

        // The preview must not disclose the foreign record's stored values.
        $encoded = json_encode($results['preview'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('PC-FOREIGN', $encoded);
        $this->assertStringNotContainsString('i5', $encoded);

        @unlink($file);
    }

    /**
     * همان سناریو برای ردیفی که پرسنلش در اسکوپ نیست — مسیر «person not
     * found» باید آن را رد کند، نه اینکه کل سازمان را ایمپورت کند.
     */
    public function test_empty_scope_rejects_a_row_whose_person_is_out_of_scope(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row()]);

        $import = new HardwareImport;
        $import->setAccessibleUnitIds([]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // #1209: the rejected row counts in the `errors` bucket.
        $this->assertSame(1, count($results['errors']));
        $this->assertSame(0, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertSame('error', $results['preview'][0]['status']);
        $this->assertDatabaseCount('hardwares', 0);

        @unlink($file);
    }

    public function test_preview_pass_without_actions_persists_nothing(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([$this->row()]);

        $import = $this->scopedImport();
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

        $import = $this->scopedImport();
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

        $import = $this->scopedImport();
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

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'فیلدهای اجباری n_code و pc_name خالی هستند',
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

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertCount(1, $results['errors']);
        $this->assertEquals(
            'پرسنل با کد ملی 9999999999 یافت نشد',
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

        $import = $this->scopedImport();
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

        $import = $this->scopedImport();
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
        // #1704: the create path keeps #890's semantics (an unrecognised shape
        // stores NULL); the SAME cell on an UPDATE row is rejected instead —
        // see test_update_with_unparseable_clean_at_is_rejected_….
        $this->createPerson();

        // #1667: distinct MACs — these are four genuinely different devices,
        // and the shared `row()` fixture MAC would make them one NIC under the
        // `both` compare key now that duplicate creates are deduped.
        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:DD:EE:0A', 'clean_at' => '2026-01-05']),
            $this->row(['pc_name' => 'PC-B', 'mac' => 'AA:BB:CC:DD:EE:0B', 'clean_at' => '05/01/2026']),
            // #890: the exporter emits Jalali `Y/m/d` and the importer used to
            // null it out, so a re-import of an exported sheet silently erased
            // `clean_at`. Jalali dashed (`1405-01-05`) must be recognised as
            // Jalali too, not stored verbatim in a `date` column.
            $this->row(['pc_name' => 'PC-C', 'mac' => 'AA:BB:CC:DD:EE:0C', 'clean_at' => '1404/11/19']),
            $this->row(['pc_name' => 'PC-D', 'mac' => 'AA:BB:CC:DD:EE:0D', 'clean_at' => '1404-11-19']),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions([
            'row_2' => 'create', 'row_3' => 'create',
            'row_4' => 'create', 'row_5' => 'create',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();
        $this->assertEquals(4, $results['created']);

        // Accepted, stored as canonical Gregorian Y-m-d.
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-A', 'clean_at' => '2026-01-05']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-C', 'clean_at' => '2026-02-08']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-D', 'clean_at' => '2026-02-08']);

        // Still rejected: an unrecognised shape, not a date we can trust.
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-B', 'clean_at' => null]);

        @unlink($file);
    }

    public function test_clean_at_rejects_out_of_calendar_jalali(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-BAD-MONTH', 'mac' => 'AA:BB:CC:DD:EE:0A', 'clean_at' => '1404/13/01']),
            $this->row(['pc_name' => 'PC-BAD-DAY', 'mac' => 'AA:BB:CC:DD:EE:0B', 'clean_at' => '1404/01/32']),
            $this->row(['pc_name' => 'PC-OK', 'mac' => 'AA:BB:CC:DD:EE:0C', 'clean_at' => '1404/12/29']),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions([
            'row_2' => 'create', 'row_3' => 'create', 'row_4' => 'create',
        ]);
        Excel::import($import, $file);

        $this->assertEquals(3, $import->getImportResults()['created']);

        // A Jalali date that does not exist must not be silently accepted:
        // fromFormat() normalises 1404/13/01 to 1405/01/02 and 1404/01/32 to
        // 1404/02/01, so shape-matching alone would store a wrong date.
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-BAD-MONTH', 'clean_at' => null]);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-BAD-DAY', 'clean_at' => null]);

        // 1404 IS a Jalali leap year, so Esfand 29 exists and is accepted.
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-OK', 'clean_at' => '2026-03-20']);

        @unlink($file);
    }

    // -----------------------------------------------------------
    //  ایشو #1704: clean_at با ارقام فارسی و ردِ ردیفِ update
    // -----------------------------------------------------------

    /**
     * #1704: an operator typing on a Persian keyboard writes `۱۴۰۴/۱۱/۱۹`. The
     * parser used to reject it, `clean_at` came back null, and the upsert wrote
     * that null over the stored date. Now the digits are folded and the row
     * stores the same Gregorian date the ASCII form produces.
     */
    public function test_update_with_persian_digit_clean_at_writes_gregorian_date(): void
    {
        $this->createPerson();

        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-NEW',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'cpu' => 'i5',
            'shutdown' => false,
            'clean_at' => null,
        ]);

        $file = $this->writeCsv([$this->row([
            'clean_at' => '۱۴۰۴/۱۱/۱۹',
        ])]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(1, $results['updated']);
        $this->assertEmpty($results['errors']);
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-NEW',
            'clean_at' => '2026-02-08',
        ]);

        @unlink($file);
    }

    /**
     * #1704: a non-empty cell the parser cannot read is NOT "no date". Writing
     * the parsed null over a stored date would destroy it silently, so the row
     * is rejected whole — the same contract #1697 used for lookup ids — and no
     * column of that row is written.
     */
    public function test_update_with_unparseable_clean_at_is_rejected_and_keeps_stored_values(): void
    {
        $this->createPerson();

        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-NEW',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'cpu' => 'i5',
            'shutdown' => false,
            'clean_at' => '2026-01-05',
        ]);

        $file = $this->writeCsv([$this->row([
            'cpu' => 'i9',
            'clean_at' => '05/01/2026',
        ])]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['updated']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('clean_at', $results['errors'][0]['error']);
        $this->assertStringContainsString('05/01/2026', $results['errors'][0]['error']);

        // The stored date survived, and the rest of the row was not written
        // either — a rejected row is rejected as a whole.
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-NEW',
            'cpu' => 'i5',
            'clean_at' => '2026-01-05',
        ]);

        @unlink($file);
    }

    /**
     * #1704: the rejection is visible in the preview the operator approves —
     * an `error` row, not a silent skip — and the preview pass writes nothing.
     */
    public function test_preview_flags_an_update_row_with_unparseable_clean_at(): void
    {
        $this->createPerson();

        Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'PC-NEW',
            'mac' => 'AA:BB:CC:DD:EE:01',
            'cpu' => 'i5',
            'shutdown' => false,
            'clean_at' => '2026-01-05',
        ]);

        $file = $this->writeCsv([$this->row([
            'cpu' => 'i9',
            'clean_at' => '05/01/2026',
        ])]);

        $import = $this->scopedImport();
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['updated']);
        $this->assertEquals(0, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('error', $results['preview'][0]['status']);
        $this->assertEquals(2, $results['preview'][0]['row']);
        $this->assertStringContainsString('05/01/2026', $results['preview'][0]['message']);

        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-NEW',
            'cpu' => 'i5',
            'clean_at' => '2026-01-05',
        ]);

        @unlink($file);
    }

    /**
     * #890: a sheet exported by HardwareExport carries Jalali dates; feeding it
     * straight back must be a no-op, not a silent `clean_at` wipe. The export
     * side is what made the operator trust the file.
     */
    public function test_export_then_reimport_preserves_clean_at(): void
    {
        $this->createPerson();

        $hardware = Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'ROUNDTRIP',
            'type' => 'pc',
            'mac' => 'AA:BB:CC:DD:EE:09',
            'shutdown' => false,
            'mark' => false,
            'clean_at' => '2026-02-08',
        ]);

        // Read the cell the way the operator would: through the real export.
        $export = new HardwareExport(
            Hardware::where('id', $hardware->id),
            ['n_code', 'pc_name', 'type', 'mac', 'shutdown', 'mark', 'clean_at'],
        );
        $exportedDate = $export->map($hardware)[array_search('clean_at', [
            'n_code', 'pc_name', 'type', 'mac', 'shutdown', 'mark', 'clean_at',
        ], true)];
        $this->assertSame('1404/11/19', $exportedDate);

        // Feed that exact value back in.
        $file = $this->writeCsv([
            $this->row([
                'pc_name' => 'ROUNDTRIP',
                'mac' => 'AA:BB:CC:DD:EE:09',
                'clean_at' => $exportedDate,
            ]),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();
        $this->assertEquals(1, $results['updated']);

        // The date survived, and no phantom change was reported for it.
        $hardware->refresh();
        $this->assertNotNull($hardware->clean_at);
        $this->assertSame('2026-02-08', $hardware->clean_at->format('Y-m-d'));

        @unlink($file);
    }

    public function test_identical_clean_at_is_not_reported_as_a_change(): void
    {
        $this->createPerson();

        $hardware = Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'NOCHANGE',
            'type' => 'pc',
            'mac' => 'AA:BB:CC:DD:EE:10',
            'shutdown' => false,
            'mark' => false,
            'clean_at' => '2026-02-08',
        ]);

        // The stored cast is a Carbon; the CSV carries the plain string. Both
        // sides must normalise to the same shape or every preview shows a diff
        // that is not one (#890).
        $file = $this->writeCsv([
            $this->row([
                'pc_name' => 'NOCHANGE',
                'mac' => 'AA:BB:CC:DD:EE:10',
                'clean_at' => '2026-02-08',
            ]),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();
        $this->assertEquals(1, $results['updated']);

        $changeFields = array_column($results['changes'], 'field');
        $this->assertNotContains(
            'clean_at',
            $changeFields,
            'An identical clean_at must not be reported as a change.'
        );

        $hardware->refresh();
        $this->assertSame('2026-02-08', $hardware->clean_at->format('Y-m-d'));

        @unlink($file);
    }

    public function test_rules_require_n_code_and_pc_name(): void
    {
        // #890: `rules()` used to sit on the class while the importer did NOT
        // implement WithValidation, so the declared `date_format:Y-m-d` rule was
        // never evaluated — a validated-looking method the runtime ignores.
        // It has been removed rather than activated, because PersonImport
        // carries neither trait and the manual per-row validation in
        // processRow() is the designed preview/confirm path. The guard below
        // keeps the two halves from drifting apart again.
        $import = new HardwareImport;

        $this->assertFalse(
            method_exists($import, 'rules'),
            'rules() must not exist without WithValidation — a dead rule set that reads as enforced.'
        );
        $this->assertFalse(
            in_array(WithValidation::class, class_implements($import), true),
            'HardwareImport deliberately does not implement WithValidation (see #890).'
        );
    }

    // -----------------------------------------------------------
    //  ایشو #1851: «0» روی فیلد متنی نباید با «خالی» یکی شود
    // -----------------------------------------------------------

    public function test_text_field_zero_is_previewed_and_written_over_empty(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['port' => null]);

        $file = $this->writeCsv([$this->row(['port' => '0'])]);

        $preview = $this->scopedImport();
        Excel::import($preview, $file);
        $results = $preview->getImportResults();

        $this->assertSame('update', $results['preview'][0]['status']);
        $this->assertArrayHasKey('port', $results['preview'][0]['changes']);
        $this->assertNull($results['preview'][0]['changes']['port']['old']);
        $this->assertSame('0', $results['preview'][0]['changes']['port']['new']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertSame('0', $hardware->fresh()->port);
        $this->assertSame(1, HardwareAudit::where('action', 'updated')->count());

        @unlink($file);
    }

    public function test_text_field_zero_is_cleared_by_an_empty_sheet_cell(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['port' => '0']);

        $file = $this->writeCsv([$this->row(['port' => ''])]);

        $preview = $this->scopedImport();
        Excel::import($preview, $file);

        $row = $preview->getImportResults()['preview'][0];
        $this->assertSame('update', $row['status']);
        $this->assertArrayHasKey('port', $row['changes']);
        $this->assertSame('0', $row['changes']['port']['old']);
        $this->assertNull($row['changes']['port']['new']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertNull($hardware->fresh()->port);

        @unlink($file);
    }

    public function test_boolean_shutdown_still_collapses_empty_and_zero(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['shutdown' => false]);

        foreach (['', '0'] as $cell) {
            $file = $this->writeCsv([$this->row(['shutdown' => $cell])]);

            $import = $this->scopedImport();
            $import->setSelectedActions(['row_2' => 'update']);
            Excel::import($import, $file);

            $results = $import->getImportResults();
            $this->assertSame([], $results['changes'], "shutdown cell '{$cell}' must not be a change");
            $this->assertSame(0, $results['updated']);
            $this->assertSame(1, $results['skipped']);

            @unlink($file);
        }

        $this->assertFalse($hardware->fresh()->shutdown);
    }

    public function test_boolean_shutdown_true_is_a_change(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['shutdown' => false]);

        $file = $this->writeCsv([$this->row(['shutdown' => '1'])]);

        $preview = $this->scopedImport();
        Excel::import($preview, $file);
        $this->assertArrayHasKey('shutdown', $preview->getImportResults()['preview'][0]['changes']);

        $confirm = $this->scopedImport();
        $confirm->setSelectedActions(['row_2' => 'update']);
        Excel::import($confirm, $file);

        $this->assertTrue($hardware->fresh()->shutdown);

        @unlink($file);
    }
}
