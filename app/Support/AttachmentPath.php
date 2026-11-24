<?php

namespace App\Support;

/**
 * One spelling for `attachments.file_path` comparisons (issue #1735).
 *
 * The column deliberately still holds the legacy prefixes some rows were
 * written with (`/storage/...`, `public/...`); nothing rewrites them. Both the
 * database side and the disk side of `attachments:prune-orphans` therefore go
 * through this helper, so a referenced file can never be mistaken for an
 * orphan just because its stored spelling differs from its path on disk.
 *
 * The strip runs to a fixed point on purpose: a single pass over
 * `['storage/', 'public/']` leaves `storage/attachments/x.pdf` behind for a
 * `public/storage/attachments/x.pdf` row. Every real upload starts with
 * `attachments/`, so stripping these two leading prefixes can never eat a
 * genuine path segment, and each pass removes at least one character, so the
 * loop always terminates.
 *
 * Comparison only — the normalised value is never written back to the column.
 */
final class AttachmentPath
{
    public static function normalize(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        do {
            $changed = false;

            foreach (['storage/', 'public/'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                    $changed = true;
                }
            }
        } while ($changed);

        return ltrim($path, '/');
    }
}
