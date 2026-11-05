<?php

namespace Tests\Support\Concerns;

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
}
