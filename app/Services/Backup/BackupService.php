<?php

namespace App\Services\Backup;

use App\Support\Backup\ApplicationVersion;
use App\Support\Backup\BackupArchive;
use App\Support\Backup\TableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use ZipArchive;

/**
 * Writes the in-app backups of issue #1375.
 *
 * A backup is a `.zip` holding one JSON document per table. The JSON is the
 * plain row array — every column, including `id` and `deleted_at`, because a
 * backup that quietly drops the soft-deleted rows is not a backup.
 *
 * The whole read happens inside ONE transaction at REPEATABLE READ
 * (expert correction C8): a full backup that read the tables one by one
 * could catch a child row whose parent was written after the parent table was
 * read, and the resulting file would fail its own validation on the way
 * back in.
 *
 * The rows are STREAMED, not collected (issue #1436): each table is written
 * to a staged `.json.part` file one 500-row page at a time and only zipped
 * afterwards, so the memory a backup needs no longer grows with the size of
 * the biggest table. Every staged file is removed when the export ends —
 * successfully or not — and the archive itself is built as `.part` and only
 * renamed into place once `ZipArchive::close()` succeeded, so neither a
 * staged file nor a half-written archive is ever left behind.
 */
class BackupService
{
    /**
     * The manifest schema version (owner decision Q3-(a)).
     */
    private const MANIFEST_VERSION = 1;

    /**
     * The rows read per page, matching `BackupImportValidator::CHUNK_SIZE`.
     */
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Back up one table.
     *
     * @throws InvalidArgumentException when the table is not part of the registry.
     */
    public function exportTable(string $table): BackupArchive
    {
        if (! $this->registry->isRegistered($table)) {
            throw new InvalidArgumentException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
        }

        $filename = $this->archiveFilename('backup-'.$table);
        $temp = $this->backupDirectory().'/'.$filename.'.'.$table.'.json.part';
        $rows = 0;

        try {
            $this->inSnapshot(function () use ($table, $temp, &$rows): void {
                $rows = $this->streamTable($table, $temp);
            });

            $manifestTables = [['table' => $table, 'rows' => $rows]];

            // A one-table archive stays a PER-TABLE archive: it carries no
            // manifest, so the reader does not mistake it for a full backup.
            $path = $this->writeArchive($filename, [$table.'.json' => $temp]);

            return new BackupArchive($path, $filename, $manifestTables);
        } finally {
            $this->removeTempFiles([$temp]);
        }
    }

    /**
     * Back up every registered table into one archive.
     */
    public function exportAll(): BackupArchive
    {
        $tables = $this->registry->tables();
        $filename = $this->archiveFilename('backup-full');
        $directory = $this->backupDirectory();

        /** @var array<string, string> $files entry name => staged file */
        $files = [];
        /** @var list<array{table: string, rows: int}> $manifestTables */
        $manifestTables = [];

        try {
            $this->inSnapshot(function () use ($tables, $directory, $filename, &$files, &$manifestTables): void {
                foreach ($tables as $table) {
                    $temp = $directory.'/'.$filename.'.'.$table.'.json.part';
                    $files[$table.'.json'] = $temp;
                    $manifestTables[] = ['table' => $table, 'rows' => $this->streamTable($table, $temp)];
                }
            });

            $path = $this->writeArchive($filename, $files, $this->manifest($manifestTables));

            return new BackupArchive($path, $filename, $manifestTables);
        } finally {
            $this->removeTempFiles(array_values($files));
        }
    }

    /**
     * Run the read inside ONE consistent snapshot.
     *
     * The isolation level can only be set as the FIRST statement of a
     * transaction, so it is set here — not inside the callback — and only
     * when the caller did not open a transaction of its own.
     *
     * @param  callable(): void  $export
     */
    private function inSnapshot(callable $export): void
    {
        $isolated = DB::connection()->transactionLevel() === 0;

        DB::transaction(function () use ($export, $isolated): void {
            if ($isolated) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            $export();
        });
    }

    /**
     * Write one table to a staged JSON file, one row at a time.
     *
     * `chunkById()` imposes `ORDER BY id`, so the rows are written in id
     * order instead of the unspecified heap order of a plain `get()`.
     *
     * The selected columns come from the registry, which excludes generated
     * columns: they are derived data and PostgreSQL rejects an explicit value
     * for them on restore (`TableRegistry::columns()`).
     *
     * @return int the number of rows written
     */
    private function streamTable(string $table, string $temp): int
    {
        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new RuntimeException("فایل موقت بک‌آپ ساخته نشد: {$temp}");
        }

        $columns = $this->registry->columns($table);
        $rows = 0;

        try {
            $this->writeTo($handle, '[');

            DB::table($table)->select($columns)->chunkById(self::CHUNK_SIZE, function ($chunk) use ($handle, &$rows): void {
                foreach ($chunk as $row) {
                    if ($rows > 0) {
                        $this->writeTo($handle, ',');
                    }

                    $this->writeTo($handle, $this->encode($this->normalizeRow($row)));
                    $rows++;
                }
            });

            $this->writeTo($handle, ']');
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * One database row as a JSON-ready array.
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(mixed $row): array
    {
        $values = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);

        $normalized = [];
        foreach ($values as $column => $value) {
            $normalized[(string) $column] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Make a raw column value safe for `json_encode`.
     *
     * A `bytea` column arrives as a stream and a PostGIS geometry arrives as
     * a hex string; both have to end up in the JSON document as text.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : null;
        }

        return $value;
    }

    /**
     * The name of the archive file — a different file for every export.
     *
     * The timestamp alone is NOT a file name: two exports in the same second
     * — two admins, or the parallel test workers that share this directory —
     * would write the SAME path, and `ZipArchive::OVERWRITE` would replace
     * the first archive with the second one. The first backup would be
     * silently lost, and a reader could get the other export's data instead
     * of its own. The random suffix makes every archive a file of its own.
     */
    private function archiveFilename(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd-His').'-'.Str::random(8).'.zip';
    }

    /**
     * The directory the archives and their staged files live in.
     */
    private function backupDirectory(): string
    {
        $directory = storage_path('app/private/backup');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("پوشهٔ بک‌آپ ساخته نشد: {$directory}");
        }

        return $directory;
    }

    /**
     * Zip the staged files into the final archive.
     *
     * The archive is written as `{$path}.part` and renamed only after a
     * checked `close()`: a crash or a broken entry never leaves a readable,
     * incomplete `*.zip` behind. A `null` manifest keeps the archive a
     * per-table backup (the reader treats a manifest as the full-backup mark).
     *
     * @param  array<string, string>  $files  entry name => staged file path
     */
    private function writeArchive(string $filename, array $files, ?string $manifest = null): string
    {
        $path = $this->backupDirectory().'/'.$filename;
        $partial = $path.'.part';

        $zip = new ZipArchive;

        try {
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("فایل بک‌آپ باز نشد: {$path}");
            }

            foreach ($files as $name => $file) {
                if (! is_file($file) || $zip->addFile($file, $name) !== true) {
                    throw new RuntimeException("فایل موقت بک‌آپ به آرشیو اضافه نشد: {$file}");
                }
            }

            if ($manifest !== null && $zip->addFromString('manifest.json', $manifest) !== true) {
                throw new RuntimeException("مانیفست بک‌آپ به آرشیو اضافه نشد: {$path}");
            }

            if ($zip->close() !== true) {
                throw new RuntimeException("فایل بک‌آپ بسته نشد: {$path}");
            }
        } catch (\Throwable $exception) {
            // Close the zip object BEFORE dropping the file: its destructor
            // would otherwise write the half-built archive back to disk.
            try {
                $zip->close();
            } catch (\Throwable) {
                // Nothing left to flush.
            }

            $this->removeTempFiles([$partial]);

            throw $exception;
        }

        if (! rename($partial, $path)) {
            $this->removeTempFiles([$partial]);

            throw new RuntimeException("فایل بک‌آپ نهایی نشد: {$path}");
        }

        return $path;
    }

    /**
     * The manifest document of a full backup.
     *
     * @param  list<array{table: string, rows: int}>  $manifestTables
     */
    private function manifest(array $manifestTables): string
    {
        return $this->encode([
            'version' => self::MANIFEST_VERSION,
            'exported_at' => now()->toIso8601String(),
            'app_version' => ApplicationVersion::resolve(),
            'tables' => $manifestTables,
        ]);
    }

    /**
     * @param  resource  $handle
     */
    private function writeTo($handle, string $contents): void
    {
        if (fwrite($handle, $contents) === false) {
            throw new RuntimeException('نوشتن فایل موقت بک‌آپ شکست خورد.');
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function removeTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  array<mixed>  $payload
     *
     * @throws JsonException
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
