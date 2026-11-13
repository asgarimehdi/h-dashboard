<?php

use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class, InteractsWithBackupFiles::class);

beforeEach(function () {
    $this->seedLookupTables();
});

/**
 * The table with enough rows to show the difference between "load the table"
 * and "stream the table": at 20k rows the row-at-once read needs ~85 MB,
 * the chunked read ~2 MB.
 */
function seedHardwares(int $rows): void
{
    $batch = 1000;

    for ($offset = 0; $offset < $rows; $offset += $batch) {
        $chunk = [];

        for ($index = 0; $index < $batch; $index++) {
            $number = $offset + $index;

            $chunk[] = [
                'n_code' => '1234567890',
                'pc_name' => 'PC-'.$number,
                'type' => 'desktop',
                'os' => 'Windows 11',
                'ip_local' => '10.0.'.$number,
                'mac' => 'aa:bb:cc:dd:ee:ff',
                'comments' => 'streaming fixture row '.$number,
            ];
        }

        DB::table('hardwares')->insert($chunk);
    }
}

test('a table export streams its rows instead of loading the whole table', function () {
    $rows = 20_000;
    $chunkSize = 500;

    seedHardwares($rows);

    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        $selects[] = $query->sql;
    });

    // Delta-based: a bare peak would include PHPUnit's own peak usage.
    memory_reset_peak_usage();
    $base = memory_get_usage(true);

    $archive = app(BackupService::class)->exportTable('hardwares');

    $peak = memory_get_peak_usage(true);

    expect($peak - $base)->toBeLessThan(48 * 1024 * 1024);

    // The memory bound alone is environment-sensitive, so the chunk count is
    // asserted structurally as well: the table is read in `limit 500` pages.
    $hardwareSelects = array_values(array_filter(
        $selects,
        fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select')
            && str_contains($sql, 'from "hardwares"')
    ));

    expect(count($hardwareSelects))->toBeGreaterThanOrEqual((int) ceil($rows / $chunkSize));

    foreach ($hardwareSelects as $sql) {
        expect(strtolower($sql))->toContain('limit '.$chunkSize);
    }

    expect($this->backupZipJson($archive->path, 'hardwares.json'))->toHaveCount($rows);

    @unlink($archive->path);
});
