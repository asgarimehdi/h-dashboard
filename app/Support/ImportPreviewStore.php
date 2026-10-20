<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Server-side home for a parsed import preview (issues #1168 / #1167).
 *
 * The preview is O(rows): it must never travel in a Livewire snapshot. The
 * component keeps only the public token; the rows, the aggregate counters and
 * the parse errors live in the cache under that token. The same token is
 * intended to be handed to the queued confirm job (#1167) together with the
 * stored file path, so the key format is a public contract, not an internal
 * detail of one component.
 */
final class ImportPreviewStore
{
    public const TTL_SECONDS = 3600;

    public static function key(string $token): string
    {
        return 'import:preview:'.$token;
    }

    /**
     * @param  array<string, mixed>  $results  `HardwareImport`/`PersonImport` results (preview, counters, errors)
     */
    public static function put(string $token, array $results): void
    {
        Cache::put(self::key($token), $results, self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $results = Cache::get(self::key($token));

        return is_array($results) ? $results : null;
    }

    public static function forget(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        Cache::forget(self::key($token));
    }

    /**
     * The parsed rows, in the order the importer emitted them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(?string $token): array
    {
        $results = self::get($token);
        $preview = $results['preview'] ?? [];

        if (! is_array($preview)) {
            return [];
        }

        $rows = [];

        foreach ($preview as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
