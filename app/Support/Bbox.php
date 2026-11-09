<?php

namespace App\Support;

/**
 * The `minLon,minLat,maxLon,maxLat` viewport parameter (issue #1245).
 *
 * One normaliser for both the request (invalid → 422) and the cache key, so a
 * viewport can never be interpreted two ways. Coordinates are WGS84 (SRID
 * 4326); the region layer turns the box into `ST_MakeEnvelope`.
 */
final class Bbox
{
    /**
     * Parse and validate a raw bbox string.
     *
     * @return array{minLon: float, minLat: float, maxLon: float, maxLat: float}|null
     */
    public static function normalize(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $parts = explode(',', $raw);

        if (count($parts) !== 4) {
            return null;
        }

        $numbers = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || ! is_numeric($part)) {
                return null;
            }

            $numbers[] = (float) $part;
        }

        [$minLon, $minLat, $maxLon, $maxLat] = $numbers;

        if ($minLon < -180.0 || $minLon > 180.0 || $maxLon < -180.0 || $maxLon > 180.0) {
            return null;
        }

        if ($minLat < -90.0 || $minLat > 90.0 || $maxLat < -90.0 || $maxLat > 90.0) {
            return null;
        }

        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            return null;
        }

        return [
            'minLon' => $minLon,
            'minLat' => $minLat,
            'maxLon' => $maxLon,
            'maxLat' => $maxLat,
        ];
    }

    /**
     * A stable cache-key fragment: six decimals is ~11 cm, so two spellings of
     * the same viewport cannot split the cache entry.
     *
     * @param  array{minLon: float, minLat: float, maxLon: float, maxLat: float}  $box
     */
    public static function toKey(array $box): string
    {
        return sprintf(
            '%.6F,%.6F,%.6F,%.6F',
            $box['minLon'],
            $box['minLat'],
            $box['maxLon'],
            $box['maxLat']
        );
    }
}
