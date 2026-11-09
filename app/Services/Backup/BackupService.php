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
 */
class BackupService
{
    /**
     * The manifest schema version (owner decision Q3-(a)).
     */
    private const MANIFEST_VERSION = 1;

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

        $rows = $this->readSnapshot([$table])[$table];

        $filename = $this->archiveFilename('backup-'.$table);
        $path = $this->writeArchive($filename, [
            $table.'.json' => $this->encode($rows),
        ]);

        return new BackupArchive($path, $filename, [
            ['table' => $table, 'rows' => count($rows)],
        ]);
    }

    /**
     * Back up every registered table into one archive.
     */
    public function exportAll(): BackupArchive
    {
        $tables = $this->registry->tables();
        $snapshot = $this->readSnapshot($tables);

        $entries = [];
        $manifestTables = [];

        foreach ($tables as $table) {
            $rows = $snapshot[$table];
            $entries[$table.'.json'] = $this->encode($rows);
            $manifestTables[] = ['table' => $table, 'rows' => count($rows)];
        }

        $entries['manifest.json'] = $this->encode([
            'version' => self::MANIFEST_VERSION,
            'exported_at' => now()->toIso8601String(),
            'app_version' => ApplicationVersion::resolve(),
            'tables' => $manifestTables,
        ]);

        $filename = $this->archiveFilename('backup-full');
        $path = $this->writeArchive($filename, $entries);

        return new BackupArchive($path, $filename, $manifestTables);
    }

    /**
     * Read the given tables from ONE consistent snapshot.
     *
     * @param  list<string>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function readSnapshot(array $tables): array
    {
        // The isolation level can only be set as the FIRST statement of a
        // transaction, so it is set here — not inside the callback — and only
        // when the caller did not open a transaction of its own.
        $isolated = DB::connection()->transactionLevel() === 0;

        /** @var array<string, list<array<string, mixed>>> $snapshot */
        $snapshot = DB::transaction(function () use ($tables, $isolated): array {
            if ($isolated) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            $snapshot = [];
            foreach ($tables as $table) {
                $snapshot[$table] = $this->readRows($table);
            }

            return $snapshot;
        });

        return $snapshot;
    }

    /**
     * Every row of a table, as plain arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function readRows(string $table): array
    {
        $rows = [];

        foreach (DB::table($table)->get() as $row) {
            $rows[] = $this->normalizeRow($row);
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
     * @param  array<string, string>  $entries
     */
    private function writeArchive(string $filename, array $entries): string
    {
        $directory = storage_path('app/private/backup');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("پوشهٔ بک‌آپ ساخته نشد: {$directory}");
        }

        $path = $directory.'/'.$filename;

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("فایل بک‌آپ باز نشد: {$path}");
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
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
