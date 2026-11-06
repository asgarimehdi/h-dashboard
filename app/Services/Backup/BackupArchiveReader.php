<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\TableRegistry;
use JsonException;
use ZipArchive;

/**
 * Opens a backup archive and hands its tables over (issue #1375).
 *
 * The reader is the first gate of a restore: a file that is not a zip, a
 * document that is not JSON, or a table that is not part of the registry is
 * refused here — before a single row is touched.
 */
class BackupArchiveReader
{
    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Read the tables of an archive.
     *
     * An archive with more than one table (or a manifest) is a FULL backup;
     * an archive with exactly one table is a per-table backup.
     *
     * @return array{tables: array<string, list<array<string, mixed>>>, full: bool}
     *
     * @throws BackupValidationException
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new BackupValidationException('فایل بک‌آپ پیدا نشد.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new BackupValidationException('فایل انتخاب‌شده یک فایل zip معتبر نیست.');
        }

        $tables = [];
        $hasManifest = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if ($name === 'manifest.json') {
                $hasManifest = true;
                $this->assertValidJson((string) $zip->getFromIndex($index), $name);

                continue;
            }

            if (! str_ends_with($name, '.json')) {
                continue;
            }

            $table = substr($name, 0, -5);

            if (! $this->registry->isRegistered($table)) {
                $zip->close();

                throw new BackupValidationException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
            }

            $tables[$table] = $this->decode((string) $zip->getFromIndex($index), $name);
        }

        $zip->close();

        if ($tables === []) {
            throw new BackupValidationException('فایل بک‌آپ هیچ جدولی ندارد.');
        }

        return [
            'tables' => $tables,
            'full' => $hasManifest || count($tables) > 1,
        ];
    }

    /**
     * Decode one JSON document of the archive into a list of rows.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BackupValidationException
     */
    private function decode(string $contents, string $entry): array
    {
        $this->assertValidJson($contents, $entry);

        $decoded = json_decode($contents, false);

        if (! is_array($decoded)) {
            throw new BackupValidationException("محتوای «{$entry}» باید آرایه‌ای از ردیف‌ها باشد.");
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_object($row)) {
                throw new BackupValidationException("محتوای «{$entry}» باید آرایه‌ای از ردیف‌ها باشد.");
            }

            $rows[] = get_object_vars($row);
        }

        return $rows;
    }

    /**
     * The document has to be JSON at all.
     *
     * @throws BackupValidationException
     */
    private function assertValidJson(string $contents, string $entry): void
    {
        try {
            json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupValidationException("محتوای «{$entry}» در فایل بک‌آپ JSON معتبر نیست.");
        }
    }
}
