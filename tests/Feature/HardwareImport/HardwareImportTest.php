<?php

namespace Tests\Feature\HardwareImport;

use App\Imports\HardwareImport;
use App\Models\Estekhdam;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareImport::class);

class HardwareImportTest extends TestCase
{
    use InteractsWithTestSetup;
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

    /**
     * ایشو #839 (Plan 50): اسکوپ صریح — این تست‌ها پیش از این پلن با اسکوپ
     * خالیِ ضمنی اجرا می‌شدند و گاردِ fail-openِ `if (! empty(...))` در
     * `HardwareImport` سبزشان نگه می‌داشت.
     */
    private function scopedImport(array $data): HardwareImport
    {
        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$data['unit']->id]);

        return $import;
    }

    public function test_import_preview_creates_new_records(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-NEW\tpc\tWindows 11\tIntel i7\t\"16384\"\tSSD 512GB\t11:22:33:44:55:66\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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

        // Include shutdown and mark columns to match database defaults
        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\tshutdown\tmark\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 10\tIntel i5\t8192\tSSD 256GB\tAA:BB:CC:DD:EE:FF\t1\t0\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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
        $import = $this->scopedImport($data);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertEquals(0, $results['created']);
        $this->assertCount(1, $results['errors']);
        // The scoped loader never indexed the out-of-scope person, so the row
        // rejects as "person not found" — the not-in-scope backstop (#839) was
        // reached only under the stale unscoped loader.
        $this->assertEquals('پرسنل با کد ملی 9876543210 یافت نشد', $results['errors'][0]['error']);

        @unlink($file);
    }

    public function test_import_creates_records_with_confirmation(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-CONFIRM\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\t33:44:55:66:77:88\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-RENAMED\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\n";
        $csvContent .= "\t\tpc\tWindows 10\n"; // Missing required fields

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        // Rejected rows count in the `errors` bucket now (#1209).
        $this->assertEquals(1, count($results['errors']));
        $this->assertEquals(0, $results['created']);
        $this->assertCount(1, $results['preview']);
        $this->assertEquals('error', $results['preview'][0]['status']);
        $this->assertEquals(
            'فیلدهای اجباری n_code و pc_name خالی هستند',
            $results['errors'][0]['error']
        );

        @unlink($file);
    }

    public function test_import_detects_changes_correctly(): void
    {
        $data = $this->createTestData();

        $csvContent = "n_code\tpc_name\ttype\tos\tcpu\tram\thdd\tmac\n";
        $csvContent .= "1234567890\tPC-001\tpc\tWindows 11\tIntel i7\t16384\tSSD 512GB\tAA:BB:CC:DD:EE:FF\n";

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, $csvContent);

        $import = $this->scopedImport($data);
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

    // ==================== #1167: bulk write contract ====================

    private function csvPath(array $rows, array $headers): string
    {
        $lines = [implode("\t", $headers)];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn ($value) => (string) $value, $row));
        }

        $file = tempnam(sys_get_temp_dir(), 'import_').'.csv';
        file_put_contents($file, implode("\n", $lines));

        return $file;
    }

    public function test_a_500_row_import_writes_every_row_and_its_audit_in_chunks(): void
    {
        $data = $this->createTestData();

        // 1,000 devices the sheet does not mention. The old loader hydrated all
        // of them in both passes; the compare-key window must ignore them.
        for ($i = 0; $i < 1000; $i++) {
            Hardware::create([
                'n_code' => $data['person']->n_code,
                'pc_name' => 'DECOY-'.$i,
                'type' => 'pc',
            ]);
        }

        $headers = ['n_code', 'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'mac'];
        $rows = [];

        for ($i = 0; $i < 250; $i++) {
            Hardware::create([
                'n_code' => $data['person']->n_code,
                'pc_name' => 'EXIST-'.$i,
                'type' => 'pc',
                'os' => 'Windows 10',
                'cpu' => 'Old CPU',
            ]);
            $rows[] = ['1234567890', 'EXIST-'.$i, 'pc', 'Windows 11', 'New CPU', '16384', 'SSD', 'AA:BB:CC:DD:EE:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
        }

        for ($i = 0; $i < 250; $i++) {
            $rows[] = ['1234567890', 'NEW-'.$i, 'pc', 'Windows 11', 'New CPU', '16384', 'SSD', '11:22:33:44:55:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
        }

        $file = $this->csvPath($rows, $headers);

        $actions = [];
        for ($i = 0; $i < 500; $i++) {
            $actions['row_'.($i + 2)] = $i < 250 ? 'update' : 'create';
        }

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions($actions);

        $this->assertQueryCount(40, function () use ($import, $file) {
            Excel::import($import, $file);
        });

        $results = $import->getImportResults();

        $this->assertSame(250, $results['created']);
        $this->assertSame(250, $results['updated']);
        $this->assertSame(0, $results['skipped']);

        $this->assertDatabaseCount('hardwares', 1501);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'NEW-249', 'os' => 'Windows 11']);
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'EXIST-249', 'cpu' => 'New CPU']);

        // One audit row per changed device — same count as the per-row path,
        // written in chunk-sized INSERTs.
        $this->assertSame(500, HardwareAudit::where('source', 'import')->count());

        @unlink($file);
    }

    public function test_import_audit_rows_carry_the_explicit_actor_and_source(): void
    {
        $data = $this->createTestData();

        $user = User::factory()->create(['n_code' => $data['person']->n_code]);

        $file = $this->csvPath(
            [['1234567890', 'PC-ACTOR', 'pc', 'Windows 11']],
            ['n_code', 'pc_name', 'type', 'os']
        );

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions(['row_2' => 'create']);
        $import->setAuditContext($user->id, 'import', '192.0.2.10', 'ImportTest/1.0');

        Excel::import($import, $file);

        $audit = HardwareAudit::where('source', 'import')->first();

        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('192.0.2.10', $audit->ip_address);
        $this->assertSame('ImportTest/1.0', $audit->user_agent);
        $this->assertSame('created', $audit->action);

        @unlink($file);
    }

    public function test_bulk_path_applies_persian_normalization_and_model_casts(): void
    {
        $data = $this->createTestData();

        // Arabic Yeh (U+064A) must fold to Persian Yeh (U+06CC); «بله»/«خاموش»
        // are the boolean tokens the exporter emits; the date is Jalali.
        $file = $this->csvPath(
            [['1234567890', "PC\u{064A}", 'pc', 'Windows 11', 'بله', 'خاموش', '1404/11/19']],
            ['n_code', 'pc_name', 'type', 'os', 'mark', 'shutdown', 'clean_at']
        );

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions(['row_2' => 'create']);

        Excel::import($import, $file);

        $hardware = Hardware::where('pc_name', "PC\u{06CC}")->first();

        $this->assertNotNull($hardware, 'Normalized pc_name was not written.');
        $this->assertTrue($hardware->mark);
        $this->assertFalse($hardware->shutdown);
        $this->assertSame('2026-02-08', $hardware->clean_at?->format('Y-m-d'));

        @unlink($file);
    }

    public function test_import_bumps_each_hardware_cache_namespace_exactly_once(): void
    {
        $data = $this->createTestData();

        $namespaces = ['hardware_stats', 'gis', 'maps', 'dashboard'];
        $before = [];
        foreach ($namespaces as $namespace) {
            $before[$namespace] = Cache::get($namespace.'_version', 0);
        }

        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['1234567890', 'CACHE-'.$i, 'pc'];
        }

        $file = $this->csvPath($rows, ['n_code', 'pc_name', 'type']);

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions(array_combine(
            array_map(fn ($i) => 'row_'.($i + 2), range(0, 19)),
            array_fill(0, 20, 'create')
        ));

        Excel::import($import, $file);

        foreach ($namespaces as $namespace) {
            $this->assertSame(
                $before[$namespace] + 1,
                Cache::get($namespace.'_version', 0),
                "{$namespace} must bump exactly once, not once per row."
            );
        }

        @unlink($file);
    }

    /**
     * #1194: `pc_name` / `mac` are not unique, so a sheet may repeat the
     * compare key. Both rows resolve to the same device, and a chunked
     * `upsert(['id'])` cannot touch the same row twice — PostgreSQL rejects
     * the whole statement with "ON CONFLICT DO UPDATE command cannot affect
     * row a second time". The pending updates are deduped by id (last write
     * wins) so the sheet imports instead of aborting the chunk.
     */
    public function test_a_duplicate_compare_key_sheet_updates_the_device_once(): void
    {
        $data = $this->createTestData();

        // The existing device, matched by pc_name.
        $file = $this->csvPath(
            [
                ['1234567890', 'PC-001', 'pc', 'Windows 11', 'Intel i7'],
                ['1234567890', 'PC-001', 'pc', 'Windows 11', 'Intel i9'],
            ],
            ['n_code', 'pc_name', 'type', 'os', 'cpu']
        );

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions(['row_2' => 'update', 'row_3' => 'update']);

        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(2, $results['updated']);
        $this->assertSame(0, $results['created']);

        // One device, and the last sheet row's value won.
        $this->assertSame(1, Hardware::where('pc_name', 'PC-001')->count());
        $this->assertDatabaseHas('hardwares', ['pc_name' => 'PC-001', 'cpu' => 'Intel i9']);

        @unlink($file);
    }

    /**
     * #1194: the bulk import path writes its audit rows through
     * `HardwareBulkAuditWriter`, which must escape a formula payload exactly
     * like `HardwareAuditObserver::sanitizeChanges` does. Before the fix the
     * raw payload was persisted by the import path while the observer escaped
     * it, so the write-time invariant differed by path.
     */
    public function test_the_import_path_escapes_formula_payloads_in_audit_rows(): void
    {
        $data = $this->createTestData();

        $payload = '=cmd|\' /c calc\'!A0';

        $file = $this->csvPath(
            [['1234567890', 'PC-001', 'pc', 'Windows 11', $payload]],
            ['n_code', 'pc_name', 'type', 'os', 'cpu']
        );

        $import = $this->scopedImport($data);
        $import->setCompareKey('pc_name');
        $import->setSelectedActions(['row_2' => 'update']);

        Excel::import($import, $file);

        $audit = HardwareAudit::where('source', 'import')->latest('id')->first();

        $this->assertNotNull($audit, 'The import wrote no audit row.');

        $changes = $audit->changes;
        $changes = is_array($changes) ? $changes : json_decode((string) $changes, true);

        $this->assertIsArray($changes);

        // Assert on the decoded values: `json_encode` escapes `/` as `\/`, so
        // the raw payload text never appears verbatim in the encoded string.
        $values = [];

        foreach ($changes as $entry) {
            foreach (['old', 'new'] as $field) {
                if (isset($entry[$field]) && is_string($entry[$field])) {
                    $values[] = $entry[$field];
                }
            }
        }

        $this->assertNotContains(
            $payload,
            $values,
            'The import path persisted a raw formula payload in an audit row.'
        );
        $this->assertContains(
            "'".$payload,
            $values,
            'The import path did not escape the formula payload.'
        );

        @unlink($file);
    }

    // ==================== #1209: confirm-pass row accounting ====================

    /**
     * The issue-body probe sheet: one row that changes an existing device and
     * one row the preview rejects (unknown person). Preview buckets must sum
     * to the sheet row count, with the rejected row in `errors`, not skipped.
     */
    public function test_preview_counts_a_rejected_row_in_errors_not_skipped(): void
    {
        $data = $this->createTestData();

        $file = $this->csvPath(
            [
                ['1234567890', 'PC-001', 'pc', 'Windows 11', 'Intel i7'],
                ['9999999999', 'PC-002', 'pc', 'Windows 11', 'Intel i7'],
            ],
            ['n_code', 'pc_name', 'type', 'os', 'cpu']
        );

        $import = $this->scopedImport($data);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['created']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(0, $results['skipped']);
        $this->assertSame(1, count($results['errors']));
        $this->assertSame(3, $results['errors'][0]['row']);
        $this->assertSame('پرسنل با کد ملی 9999999999 یافت نشد', $results['errors'][0]['error']);

        // The probe's preview statuses: [update, error].
        $this->assertSame(['update', 'error'], array_column($results['preview'], 'status'));
        // Contract #1: the buckets must sum to the sheet row count (2 rows).
        $this->assertSame(2, $results['created'] + $results['updated'] + $results['skipped'] + count($results['errors']));

        @unlink($file);
    }

    /**
     * The issue's other confirm case: a realistic action map where the
     * operator also picked an action for the rejected row. The rejected row
     * must still be counted (skipped) and must surface in the final `errors`
     * with the preview's own message.
     */
    public function test_confirm_pass_carries_preview_rejections_into_the_result(): void
    {
        $data = $this->createTestData();

        $file = $this->csvPath(
            [
                ['1234567890', 'PC-001', 'pc', 'Windows 11', 'Intel i7'],
                ['9999999999', 'PC-002', 'pc', 'Windows 11', 'Intel i7'],
            ],
            ['n_code', 'pc_name', 'type', 'os', 'cpu']
        );

        $import = $this->scopedImport($data);
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
            'پرسنل با کد ملی 9999999999 یافت نشد',
            $results['errors'][0]['error'],
            "The confirm pass must keep the preview's own per-row error message."
        );
        $this->assertDatabaseCount('hardwares', 1);

        @unlink($file);
    }

    /**
     * The issue's all-skipped confirm case: even when the operator skips
     * every row, every sheet row must land in a bucket — nothing lost.
     */
    public function test_confirm_pass_counts_operator_skipped_rows(): void
    {
        $data = $this->createTestData();

        $file = $this->csvPath(
            [
                ['1234567890', 'PC-001', 'pc', 'Windows 11', 'Intel i7'],
                ['9999999999', 'PC-002', 'pc', 'Windows 11', 'Intel i7'],
            ],
            ['n_code', 'pc_name', 'type', 'os', 'cpu']
        );

        $import = $this->scopedImport($data);
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
            'پرسنل با کد ملی 9999999999 یافت نشد',
            $results['errors'][0]['error']
        );
        $this->assertDatabaseCount('hardwares', 1);

        @unlink($file);
    }

    /**
     * The issue's browser case: an unchanged device must count the same in
     * preview and confirm — «۱ بدون تغییر» in both, nothing dropped.
     */
    public function test_confirm_pass_of_an_unchanged_row_matches_the_preview_count(): void
    {
        $data = $this->createTestData();

        $file = $this->csvPath(
            [['1234567890', 'PC-001', 'pc', 'Windows 10', 'Intel i5', '8192', 'SSD 256GB', 'AA:BB:CC:DD:EE:FF', '1', '0']],
            ['n_code', 'pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'mac', 'shutdown', 'mark']
        );

        $preview = $this->scopedImport($data);
        Excel::import($preview, $file);
        $previewResults = $preview->getImportResults();

        $confirm = $this->scopedImport($data);
        $confirm->setSelectedActions(['row_2' => 'skip']);
        Excel::import($confirm, $file);
        $confirmResults = $confirm->getImportResults();

        $this->assertSame($previewResults['skipped'], $confirmResults['skipped']);
        $this->assertSame(1, $confirmResults['skipped']);
        $this->assertSame(0, $confirmResults['created']);
        $this->assertSame(0, $confirmResults['updated']);
        $this->assertSame([], $confirmResults['errors']);
        $this->assertSame(1, $confirmResults['created'] + $confirmResults['updated'] + $confirmResults['skipped']);

        @unlink($file);
    }
}
