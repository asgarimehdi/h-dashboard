<?php

namespace Tests\Support\Concerns;

use App\Support\Backup\TableRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Helpers for reading (and forging) the `.zip` archives the backup feature
 * produces (issue #1375).
 *
 * Every backup file is a zip of JSON documents, so the tests need to open the
 * archive, look at one entry and — for the validation tests — build archives
 * that are broken on purpose.
 */
trait InteractsWithBackupFiles
{
    /**
     * Every entry of a zip: entry name => raw contents.
     *
     * @return array<string, string>
     */
    protected function backupZipEntries(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open the backup archive: {$path}");
        }

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $contents = $zip->getFromIndex($index);
            $entries[$name] = $contents === false ? '' : $contents;
        }

        $zip->close();

        return $entries;
    }

    /**
     * One entry of a zip, decoded as JSON.
     *
     * @return array<mixed>
     */
    protected function backupZipJson(string $path, string $entry): array
    {
        $entries = $this->backupZipEntries($path);

        if (! array_key_exists($entry, $entries)) {
            throw new RuntimeException(
                "The archive has no entry «{$entry}»; it holds: ".implode(', ', array_keys($entries))
            );
        }

        $decoded = json_decode($entries[$entry], true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The archive entry «{$entry}» is not a JSON array.");
        }

        return $decoded;
    }

    /**
     * Build a zip archive out of raw strings — for the archives that must be
     * broken on purpose.
     *
     * @param  array<string, string>  $files
     */
    protected function backupMakeZip(string $path, array $files): string
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * Build a large table archive without ever holding the rows in PHP memory
     * (issue #1418).
     *
     * The rows are generated one at a time from a database template and
     * written straight to a JSON temp file; `ZipArchive::addFile()` then adds
     * that file to the archive. The optional mutator receives the zero-based
     * index and the generated row, so a test can move one id or make one row
     * invalid without building a 25,000-row PHP array.
     *
     * @param  (callable(int, array<string, mixed>): array<string, mixed>)|null  $mutate
     */
    protected function backupMakeGeneratedZip(string $path, string $table, int $rows, ?callable $mutate = null): string
    {
        $columns = app(TableRegistry::class)->columns($table);
        $template = (array) (DB::table($table)->first() ?? []);

        $json = tempnam(sys_get_temp_dir(), 'backup-json-');

        if ($json === false) {
            throw new RuntimeException("Cannot create a temp file for the {$table} archive.");
        }

        $handle = fopen($json, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the temp file for the {$table} archive.");
        }

        fwrite($handle, '[');

        for ($index = 0; $index < $rows; $index++) {
            $row = [];

            foreach ($columns as $column) {
                $row[$column] = $template[$column] ?? null;
            }

            $row['id'] = $index + 1;

            if ($mutate !== null) {
                $row = $mutate($index, $row);
            }

            if ($index > 0) {
                fwrite($handle, ',');
            }

            fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        fwrite($handle, ']');
        fclose($handle);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($json, $table.'.json');
        $zip->close();

        @unlink($json);

        return $path;
    }
}
