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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Behavioural characterisation for HardwareImport (#1813).
 *
 * Written against the pre-extraction implementation; must stay green, byte-for-
 * byte unchanged, after HardwareImport moves onto AbstractImportPipeline. Pins
 * the preview/confirm result shape, mac rename detection (#1758), the
 * clean_at contract (#1704) and duplicate-sheet-row collapsing (#1667).
 */
covers(HardwareImport::class);

class HardwareImportCharacterizationTest extends TestCase
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
     * @return array<string, string>
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

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $content = $this->header()."\n";
        foreach ($rows as $row) {
            $content .= implode("\t", $row)."\n";
        }

        $file = tempnam(sys_get_temp_dir(), 'hardware_import_char_').'.csv';
        file_put_contents($file, $content);

        return $file;
    }

    private function createPerson(string $nCode = '1234567890'): void
    {
        $this->unit = Unit::create(['name' => 'واحد تست']);

        Person::create([
            'n_code' => $nCode,
            'f_name' => 'احمد',
            'l_name' => 'محمدی',
            't_id' => Tahsil::create(['name' => 'لیسانس'])->id,
            'e_id' => Estekhdam::create(['name' => 'رسمی'])->id,
            's_id' => Semat::create(['name' => 'تکنسین'])->id,
            'r_id' => Radif::create(['name' => 'ردیف 1'])->id,
            'u_id' => $this->unit->id,
        ]);
    }

    private function scopedImport(): HardwareImport
    {
        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$this->unit->id]);

        return $import;
    }

    /**
     * @param  array<string, mixed>  $overrides
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

    /**
     * @return array<string, mixed>
     */
    private function mappedRow(
        string $pcName,
        string $mac,
        string $cpu,
        bool $shutdown = false,
        bool $mark = false,
        ?string $cleanAt = null,
    ): array {
        return [
            'n_code' => '1234567890',
            'pc_name' => $pcName,
            'type' => 'pc',
            'os' => 'Windows 11',
            'ip_valid' => '10.1.1.5',
            'ip_local' => '192.168.1.5',
            'mac' => $mac,
            'net_type' => 'LAN',
            'switch' => 'SW-1',
            'port' => '5',
            'shutdown' => $shutdown,
            'vlan' => '10',
            'motherboard' => 'H610',
            'cpu' => $cpu,
            'ram' => '16GB',
            'hdd' => 'SSD 512',
            'comments' => null,
            'mark' => $mark,
            'clean_at' => $cleanAt,
        ];
    }

    public function test_golden_preview_and_confirm_snapshot(): void
    {
        $this->createPerson();

        $updateTarget = $this->seedHardware([
            'pc_name' => 'PC-UPD',
            'mac' => 'AA:BB:CC:DD:EE:02',
            'cpu' => 'i5',
        ]);
        $unchangedTarget = $this->seedHardware([
            'pc_name' => 'PC-SAME',
            'mac' => 'AA:BB:CC:DD:EE:03',
            'cpu' => 'i5',
        ]);

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-UPD', 'mac' => 'AA:BB:CC:DD:EE:02', 'cpu' => 'i9']),
            $this->row(['pc_name' => 'PC-SAME', 'mac' => 'AA:BB:CC:DD:EE:03', 'cpu' => 'i5']),
            $this->row(['pc_name' => 'PC-CREATE', 'mac' => 'AA:BB:CC:DD:EE:04']),
            $this->row(['pc_name' => '']),
        ]);

        $updateData = $this->mappedRow('PC-UPD', 'AA:BB:CC:DD:EE:02', 'i9');
        $unchangedData = $this->mappedRow('PC-SAME', 'AA:BB:CC:DD:EE:03', 'i5');
        $createData = $this->mappedRow('PC-CREATE', 'AA:BB:CC:DD:EE:04', 'i7');
        $errorData = $this->mappedRow('', 'AA:BB:CC:DD:EE:01', 'i7');
        $errorData['pc_name'] = null;

        $preview = [
            [
                'row' => 2,
                'status' => 'update',
                'id' => $updateTarget->id,
                'pc_name' => 'PC-UPD',
                'match_key' => 'pc_name',
                'changes' => [
                    'cpu' => ['old' => 'i5', 'new' => 'i9'],
                ],
                'person' => 'احمد محمدی',
                'data' => $updateData,
            ],
            [
                'row' => 3,
                'status' => 'unchanged',
                'id' => $unchangedTarget->id,
                'pc_name' => 'PC-SAME',
                'match_key' => 'pc_name',
                'message' => 'بدون تغییر',
                'person' => 'احمد محمدی',
                'data' => $unchangedData,
            ],
            [
                'row' => 4,
                'status' => 'create',
                'pc_name' => 'PC-CREATE',
                'person' => 'احمد محمدی',
                'data' => $createData,
            ],
            [
                'row' => 5,
                'status' => 'error',
                'message' => 'فیلدهای اجباری n_code و pc_name خالی هستند',
                'data' => $errorData,
            ],
        ];

        $previewImport = $this->scopedImport();
        Excel::import($previewImport, $file);

        $this->assertSame([
            'created' => 1,
            'updated' => 1,
            'skipped' => 1,
            'errors' => [[
                'row' => 5,
                'error' => 'فیلدهای اجباری n_code و pc_name خالی هستند',
                'data' => $errorData,
            ]],
            'changes' => [],
            'preview' => $preview,
        ], $previewImport->getImportResults());

        $confirmImport = $this->scopedImport();
        $confirmImport->setSelectedActions([
            'row_2' => 'update',
            'row_3' => 'skip',
            'row_4' => 'create',
            'row_5' => 'create',
        ]);
        Excel::import($confirmImport, $file);

        $this->assertSame([
            'created' => 1,
            'updated' => 1,
            'skipped' => 2,
            'errors' => [[
                'row' => 5,
                'error' => 'فیلدهای اجباری n_code و pc_name خالی هستند',
                'data' => $errorData,
            ]],
            'changes' => [[
                'row' => 2,
                'id' => $updateTarget->id,
                'pc_name' => 'PC-UPD',
                'match_key' => 'pc_name',
                'changes' => [
                    'cpu' => ['old' => 'i5', 'new' => 'i9'],
                ],
            ]],
            'preview' => $preview,
        ], $confirmImport->getImportResults());

        $this->assertDatabaseHas('hardwares', [
            'id' => $updateTarget->id,
            'pc_name' => 'PC-UPD',
            'cpu' => 'i9',
        ]);
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-CREATE',
            'mac' => 'AA:BB:CC:DD:EE:04',
        ]);
        $this->assertSame(1, HardwareAudit::where('source', 'import')->where('action', 'updated')->count());
        $this->assertSame(1, HardwareAudit::where('source', 'import')->where('action', 'created')->count());

        @unlink($file);
    }

    public function test_mac_match_rename_is_previewed_written_and_audited(): void
    {
        $this->createPerson();
        $hardware = $this->seedHardware(['pc_name' => 'PC-OLD', 'cpu' => 'i5']);

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-NEW', 'cpu' => 'i9']),
        ]);

        $import = $this->scopedImport();
        $import->setCompareKey('mac');
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('update', $results['preview'][0]['status']);
        $this->assertSame('mac', $results['preview'][0]['match_key']);
        $this->assertSame(['pc_name', 'cpu'], array_keys($results['preview'][0]['changes']));
        $this->assertSame(['old' => 'PC-OLD', 'new' => 'PC-NEW'], $results['preview'][0]['changes']['pc_name']);
        $this->assertSame(1, $results['updated']);

        $this->assertDatabaseHas('hardwares', [
            'id' => $hardware->id,
            'pc_name' => 'PC-NEW',
            'cpu' => 'i9',
        ]);

        $audit = HardwareAudit::where('source', 'import')->where('action', 'updated')->firstOrFail();
        $changes = is_array($audit->changes) ? $audit->changes : json_decode((string) $audit->changes, true);
        $pcNameChange = collect($changes)->firstWhere('field', 'pc_name');
        $this->assertEquals(['field' => 'pc_name', 'old' => 'PC-OLD', 'new' => 'PC-NEW'], $pcNameChange);

        @unlink($file);
    }

    public function test_persian_digit_clean_at_is_previewed_and_written_as_gregorian(): void
    {
        $this->createPerson();
        $this->seedHardware(['cpu' => 'i7']);

        $file = $this->writeCsv([
            $this->row(['clean_at' => '۱۴۰۴/۱۱/۱۹']),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame('update', $results['preview'][0]['status']);
        $this->assertSame([
            'clean_at' => ['old' => null, 'new' => '2026-02-08'],
        ], $results['preview'][0]['changes']);
        $this->assertSame(1, $results['updated']);
        $this->assertDatabaseHas('hardwares', ['clean_at' => '2026-02-08']);

        @unlink($file);
    }

    public function test_unparseable_clean_at_rejects_the_whole_update_row(): void
    {
        $this->createPerson();
        $this->seedHardware(['cpu' => 'i5', 'clean_at' => '2026-01-05']);

        $file = $this->writeCsv([
            $this->row(['cpu' => 'i9', 'clean_at' => '05/01/2026']),
        ]);

        $import = $this->scopedImport();
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('clean_at', $results['errors'][0]['error']);
        $this->assertStringContainsString('05/01/2026', $results['errors'][0]['error']);

        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'PC-NEW',
            'cpu' => 'i5',
            'clean_at' => '2026-01-05',
        ]);

        @unlink($file);
    }

    public function test_identical_sheet_rows_collapse_to_one_create_and_report_the_dropped_row(): void
    {
        $this->createPerson();

        $duplicate = $this->row([
            'pc_name' => 'PC-DUP',
            'mac' => 'AA:BB:CC:00:00:01',
            'cpu' => 'Intel i7',
        ]);

        $file = $this->writeCsv([$duplicate, $duplicate]);

        $import = $this->scopedImport();
        $import->setCompareKey('both');
        $import->setSelectedActions(['row_2' => 'create', 'row_3' => 'create']);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(1, $results['created']);
        $this->assertSame(1, Hardware::where('pc_name', 'PC-DUP')->count());
        $this->assertSame(1, HardwareAudit::where('source', 'import')->where('action', 'created')->count());
        $this->assertCount(1, $results['errors']);
        $this->assertSame(2, $results['errors'][0]['row']);
        $this->assertSame('pc_name', $results['errors'][0]['key']);
        $this->assertSame('PC-DUP', $results['errors'][0]['value']);
        $this->assertSame(3, $results['errors'][0]['duplicate_of']);

        @unlink($file);
    }

    public function test_replaced_duplicate_releases_its_keys(): void
    {
        $this->createPerson();

        $file = $this->writeCsv([
            $this->row(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:01']),
            $this->row(['pc_name' => 'PC-B', 'mac' => 'AA:BB:CC:00:00:01']),
            $this->row(['pc_name' => 'PC-A', 'mac' => 'AA:BB:CC:00:00:02']),
        ]);

        $import = $this->scopedImport();
        $import->setCompareKey('both');
        $import->setSelectedActions([
            'row_2' => 'create',
            'row_3' => 'create',
            'row_4' => 'create',
        ]);
        Excel::import($import, $file);

        $results = $import->getImportResults();

        $this->assertSame(2, $results['created']);
        $this->assertSame(1, Hardware::where('pc_name', 'PC-B')->count());
        $this->assertSame(1, Hardware::where('pc_name', 'PC-A')->count());
        $this->assertSame(1, Hardware::where('pc_name', 'PC-A')->where('mac', 'AA:BB:CC:00:00:02')->count());
        $this->assertCount(1, $results['errors']);
        $this->assertSame(2, $results['errors'][0]['row']);
        $this->assertSame('mac', $results['errors'][0]['key']);
        $this->assertSame(3, $results['errors'][0]['duplicate_of']);

        @unlink($file);
    }
}
