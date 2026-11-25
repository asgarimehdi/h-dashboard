<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The one place an attachment file is deleted from disk (issue #1776).
 *
 * Before this helper the logic lived twice — in `Attachment::deleting` and in
 * `Ticket::deleting` — and the backup restore path had no way to reuse it at
 * all. Both hooks and the restore now go through this class, so the traversal
 * guard and the path normalisation can never drift apart again.
 *
 * Three rules, inherited from the two hooks it replaces:
 *
 * 1. Every path is put through {@see AttachmentPath::normalize()} first: the
 *    column still holds legacy `/storage/...` and `public/...` spellings, and
 *    comparing them raw would delete the wrong file (issue #1735).
 * 2. A path with a `..` segment is refused, exactly like the download
 *    controller: `file_path` is database data, but it must never address a
 *    file outside the disk root.
 * 3. A missing file is not an error: the `local` disk sets `throw => false`
 *    (#1276), so a row whose file is already gone still deletes cleanly.
 */
final class AttachmentFiles
{
    /**
     * How many paths one `delete()` call may carry.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Delete the given paths from the private `local` disk.
     *
     * Empty and unsafe paths are skipped instead of failing the caller — a
     * restore must not roll back because one row carries a bad path.
     *
     * @param  iterable<int, string|null>  $paths
     */
    public static function delete(iterable $paths): void
    {
        $safe = [];

        foreach ($paths as $path) {
            $normalized = AttachmentPath::normalize((string) $path);

            if ($normalized === '' || in_array('..', explode('/', $normalized), true)) {
                continue;
            }

            $safe[] = $normalized;
        }

        foreach (array_chunk(array_values(array_unique($safe)), self::CHUNK_SIZE) as $chunk) {
            Storage::disk('local')->delete($chunk);
        }
    }
}
