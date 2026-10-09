<?php

namespace Tests\Feature;

use App\Exports\HardwareExport;
use App\Models\Hardware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * #890: one malformed row must not kill the whole export download.
 *
 * `resolveValue()` formatted `clean_at` with an unguarded
 * `Jalalian::fromCarbon()`, so a row whose stored value is not a real calendar
 * date threw out of the mapper and 500'd the export for every other row in the
 * operator's scope. The list page and the JSON API survived because they format
 * the raw cast, so the row only looked healthy until somebody clicked Export.
 */
covers(HardwareExport::class);

class HardwareExportBadRowTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = ['n_code', 'pc_name', 'type', 'mac', 'shutdown', 'mark', 'clean_at'];

    private function hardware(string $name): Hardware
    {
        return Hardware::create([
            'n_code' => '1234567890',
            'pc_name' => $name,
            'type' => 'pc',
            'mac' => 'AA:BB:CC:DD:EE:'.substr(md5($name), 0, 2),
            'shutdown' => false,
            'mark' => false,
        ]);
    }

    private function export(): HardwareExport
    {
        return new HardwareExport(Hardware::query()->orderBy('id'), self::COLUMNS);
    }

    /**
     * The row exactly as the exporter hands it to `map()` — an Eloquent model
     * read fresh from the table, not the in-memory instance.
     */
    private function freshRow(string $name): Hardware
    {
        return Hardware::where('pc_name', $name)->firstOrFail();
    }

    public function test_export_succeeds_when_one_row_has_an_impossible_clean_at(): void
    {
        $good = $this->hardware('GOOD');
        $good->clean_at = '2026-02-08';
        $good->save();

        // The poisoned row: a Jalali date stored verbatim by the old importer,
        // so it sits in a `date` column as a non-Gregorian year.
        $bad = $this->hardware('BAD');
        DB::table('hardwares')->where('id', $bad->id)->update(['clean_at' => '783-01-01']);

        $other = $this->hardware('ANOTHER_GOOD');
        $other->clean_at = '2026-02-08';
        $other->save();

        $export = $this->export();
        $cleanAtIndex = array_search('clean_at', self::COLUMNS, true);

        // Every row in the operator's scope maps — the bad one degrades to a
        // dash instead of throwing out of the whole download.
        $mapped = [
            $export->map($this->freshRow('GOOD')),
            $export->map($this->freshRow('BAD')),
            $export->map($this->freshRow('ANOTHER_GOOD')),
        ];

        $this->assertSame('1404/11/19', $mapped[0][$cleanAtIndex]);
        $this->assertSame('-', $mapped[1][$cleanAtIndex]);
        $this->assertSame('1404/11/19', $mapped[2][$cleanAtIndex]);
    }

    public function test_export_actually_writes_a_file_with_a_poisoned_row(): void
    {
        $good = $this->hardware('GOOD');
        $good->clean_at = '2026-02-08';
        $good->save();

        $bad = $this->hardware('BAD');
        DB::table('hardwares')->where('id', $bad->id)->update(['clean_at' => '783-01-01']);

        // This is the call that used to throw an uncaught
        // Assert\InvalidArgumentException from inside the row mapper.
        Excel::store($this->export(), 'hardware-export-bad-row.xlsx', 'local');

        $path = Storage::disk('local')->path('hardware-export-bad-row.xlsx');
        $this->assertFileExists($path);

        @unlink($path);
    }

    public function test_a_valid_clean_at_is_formatted_as_jalali(): void
    {
        // Sanity: the guard must not swallow the normal path.
        $hardware = $this->hardware('GOOD');
        $hardware->clean_at = '2026-02-08';
        $hardware->save();

        $cleanAtIndex = array_search('clean_at', self::COLUMNS, true);
        $mapped = $this->export()->map($this->freshRow('GOOD'));

        $this->assertSame('1404/11/19', $mapped[$cleanAtIndex]);
    }

    public function test_a_missing_clean_at_is_reported_as_a_dash(): void
    {
        $this->hardware('GOOD');

        $cleanAtIndex = array_search('clean_at', self::COLUMNS, true);
        $mapped = $this->export()->map($this->freshRow('GOOD'));

        $this->assertSame('-', $mapped[$cleanAtIndex]);
    }
}
