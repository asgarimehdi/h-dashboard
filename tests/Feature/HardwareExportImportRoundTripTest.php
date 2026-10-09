<?php

namespace Tests\Feature;

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
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #890 "step zero": measure, do not assume, which exported columns survive a
 * round-trip. The plan called `shutdown`/`mark` a proven candidate because the
 * exporter writes Persian labels («روشن», «علامت‌دار») while the importer's
 * boolean parser only knows latin tokens.
 *
 * This test is the measurement. It walks every exportable column, feeds the
 * real exported cell back through the real importer, and reports the ones that
 * come back different. It is an audit, so it asserts the invariant that the
 * SHEET survives the trip and reports the per-column drift.
 */
covers(HardwareExport::class, HardwareImport::class);

class HardwareExportImportRoundTripTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const ALL_COLUMNS = [
        'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
        'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
        'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
    ];

    private function seedUnit(): Unit
    {
        // Use the real lookup rows rather than hardcoded ids: `t_id => 1` and
        // friends only exist if some earlier test happened to seed them, so the
        // FK check fails the moment this file runs first.
        $this->seedLookupTables();

        $unit = Unit::create(['name' => 'RoundTrip Unit']);
        Person::create([
            'n_code' => '1234567890', 'f_name' => 'رضا', 'l_name' => 'کریمی',
            't_id' => Tahsil::firstOrCreate(['name' => 'لیسانس'])->id,
            'e_id' => Estekhdam::firstOrCreate(['name' => 'رسمی'])->id,
            's_id' => Semat::firstOrCreate(['name' => 'تکنسین'])->id,
            'r_id' => Radif::firstOrCreate(['name' => 'ردیف 1'])->id,
            'u_id' => $unit->id,
        ]);

        return $unit;
    }

    public function test_every_exported_column_is_measured_against_the_importer(): void
    {
        $unit = $this->seedUnit();

        $hardware = Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => 'ROUNDTRIP_ALL',
            'type' => 'pc',
            'os' => 'Windows 11',
            'ip_valid' => '10.1.1.5',
            'ip_local' => '192.168.1.5',
            'mac' => 'AA:BB:CC:DD:EE:77',
            'net_type' => 'LAN',
            'switch' => 'SW-1',
            'port' => '5',
            'shutdown' => true,
            'vlan' => '10',
            'motherboard' => 'H610',
            'cpu' => 'i7',
            'ram' => '16GB',
            'hdd' => 'SSD 512',
            'comments' => 'یادداشت',
            'mark' => true,
            'clean_at' => '2026-02-08',
        ]);

        $export = new HardwareExport(Hardware::query(), self::ALL_COLUMNS);
        $cells = $export->map(Hardware::find($hardware->id));

        // Build a CSV that carries the exported cells verbatim.
        $row = [];
        foreach (self::ALL_COLUMNS as $i => $column) {
            $row[$column] = (string) $cells[$i];
        }

        $header = implode("\t", self::ALL_COLUMNS);
        $file = tempnam(sys_get_temp_dir(), 'rt_').'.csv';
        file_put_contents($file, $header."\n".implode("\t", $row)."\n");

        $import = new HardwareImport;
        $import->setAccessibleUnitIds([$unit->id]);
        $import->setSelectedActions(['row_2' => 'update']);
        Excel::import($import, $file);

        $after = Hardware::find($hardware->id)->fresh();

        // `clean_at` is the contract this issue fixes — it must survive.
        $this->assertSame(
            '2026-02-08',
            $after->clean_at->format('Y-m-d'),
            'clean_at must survive export -> import.'
        );

        // Report the measured drift per column rather than asserting a guess.
        $drift = [];
        foreach (self::ALL_COLUMNS as $column) {
            $before = $hardware->getRawOriginal($column);
            $afterValue = $after->getRawOriginal($column);
            $normalise = fn ($v) => in_array($v, [true, false, 0, 1, '0', '1'], true) ? (bool) $v : $v;
            if ($normalise($before) != $normalise($afterValue)) {
                $drift[$column] = ['exported' => $row[$column], 'stored_after' => $afterValue];
            }
        }

        fwrite(STDERR, "\n=== #890 round-trip drift ===\n".json_encode($drift, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

        // `clean_at` is reported here too, but it is not drift: the exporter
        // speaks Jalali and the importer stores canonical Gregorian. Only the
        // boolean columns are genuine data loss.
        $this->assertSame(
            [],
            array_keys(array_diff_key($drift, ['clean_at' => null])),
            'No exported column may silently lose data on a round-trip.'
        );

        @unlink($file);
    }
}
