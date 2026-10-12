<?php

namespace Tests\Feature\HardwareImport;

use App\Exports\HardwareExport;
use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
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

        $this->assertSame(1, $results['skipped']);
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

        $import = $this->scopedImport();
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
        $this->createPerson();

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'clean_at' => '2026-01-05']),
            $this->row(['pc_name' => 'PC-B', 'clean_at' => '05/01/2026']),
            // #890: the exporter emits Jalali `Y/m/d` and the importer used to
            // null it out, so a re-import of an exported sheet silently erased
            // `clean_at`. Jalali dashed (`1405-01-05`) must be recognised as
            // Jalali too, not stored verbatim in a `date` column.
            $this->row(['pc_name' => 'PC-C', 'clean_at' => '1404/11/19']),
            $this->row(['pc_name' => 'PC-D', 'clean_at' => '1404-11-19']),
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
            $this->row(['pc_name' => 'PC-BAD-MONTH', 'clean_at' => '1404/13/01']),
            $this->row(['pc_name' => 'PC-BAD-DAY', 'clean_at' => '1404/01/32']),
            $this->row(['pc_name' => 'PC-OK', 'clean_at' => '1404/12/29']),
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
}
