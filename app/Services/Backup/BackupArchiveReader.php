<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use App\Support\Backup\TableRegistry;
use Generator;
use JsonException;
use ZipArchive;

/**
 * Opens a backup archive and hands its tables over (issue #1375).
 *
 * The reader is the first gate of a restore: a file that is not a zip, a
 * document that is not JSON, or a table that is not part of the registry is
 * refused here — before a single row is touched.
 *
 * ## Why the archive is not decoded up front (issue #1418)
 *
 * `read()` used to decode every table into one nested PHP array. On a
 * 200,000-row table that alone peaked at ~658 MB, so the restore failed long
 * before the database did. `inspect()` now reads only entry names (and the
 * manifest), while `rows()` streams one table through
 * {@see JsonArrayRowStream}. Memory is one chunk plus one row.
 */
class BackupArchiveReader
{
    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Inspect the archive without decoding a single table.
     *
     * An archive with more than one table (or a manifest) is a FULL backup;
     * an archive with exactly one table is a per-table backup.
     *
     * @return array{tables: list<string>, full: bool}
     *
     * @throws BackupValidationException
     */
    public function inspect(string $path): array
    {
        $zip = $this->open($path);

        try {
            $tables = [];
            $hasManifest = false;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if ($name === 'manifest.json') {
                    $hasManifest = true;
                    $contents = $zip->getFromIndex($index);
                    $this->assertValidJson($contents === false ? '' : $contents, $name);

                    continue;
                }

                if (! str_ends_with($name, '.json')) {
                    continue;
                }

                $table = substr($name, 0, -5);

                if (! $this->registry->isRegistered($table)) {
                    throw new BackupValidationException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
                }

                $tables[] = $table;
            }

            if ($tables === []) {
                throw new BackupValidationException('فایل بک‌آپ هیچ جدولی ندارد.');
            }

            return [
                'tables' => $tables,
                'full' => $hasManifest || count($tables) > 1,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Stream the rows of one table.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws BackupValidationException
     */
    public function rows(string $path, string $table): Generator
    {
        if (! $this->registry->isRegistered($table)) {
            throw new BackupValidationException("جدول «{$table}» در رجیستری بک‌آپ نیست.");
        }

        $zip = $this->open($path);

        try {
            $index = $zip->locateName($table.'.json');

            if ($index === false) {
                throw new BackupValidationException("جدول «{$table}» در فایل بک‌آپ نیست.");
            }

            $stream = $zip->getStreamIndex($index);

            if ($stream === false) {
                throw new BackupValidationException("محتوای «{$table}.json» در فایل بک‌آپ خوانده نشد.");
            }

            try {
                yield from (new JsonArrayRowStream($stream, $table.'.json'))->rows();
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws BackupValidationException
     */
    private function open(string $path): ZipArchive
    {
        if (! is_file($path)) {
            throw new BackupValidationException('فایل بک‌آپ پیدا نشد.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new BackupValidationException('فایل انتخاب‌شده یک فایل zip معتبر نیست.');
        }

        return $zip;
    }

    /**
     * The document has to be JSON at all (used for the manifest only).
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
