<?php

namespace App\Support;

/**
 * Nearest-rank quantile breaks for the map legend (decision 21).
 *
 * The map colours rates by quantile, never linearly: a linear scale collapses
 * into one shade whenever a single district has an outlier rate. Five classes
 * are the contract; the class index is what the client renders, so no quantile
 * arithmetic is repeated in JavaScript (#1246).
 *
 * Missing rates (`null`) are dropped before bucketing — a district with no
 * population has no rate and must not drag the scale down to zero.
 */
final class QuantileBuckets
{
    /**
     * Upper-bound breaks for the requested number of classes.
     *
     * The returned list is shorter than `$classes - 1` when the input has
     * fewer distinct values (all-equal rates collapse to one break), and empty
     * when there is nothing to bucket.
     *
     * @param  array<int, float|null>  $rates
     * @return list<float>
     */
    public static function breaks(array $rates, int $classes = 5): array
    {
        if ($classes < 1) {
            return [];
        }

        $values = [];

        foreach ($rates as $rate) {
            if ($rate !== null) {
                $values[] = (float) $rate;
            }
        }

        sort($values, SORT_NUMERIC);

        $count = count($values);

        if ($count === 0) {
            return [];
        }

        $breaks = [];

        for ($i = 1; $i < $classes; $i++) {
            $index = (int) ceil($i * $count / $classes) - 1;
            $index = min(max($index, 0), $count - 1);
            $candidate = $values[$index];

            if ($breaks === [] || end($breaks) !== $candidate) {
                $breaks[] = $candidate;
            }
        }

        return $breaks;
    }

    /**
     * Class index for one rate: the number of upper-bound breaks it exceeds.
     *
     * @param  list<float>  $breaks
     */
    public static function classOf(?float $rate, array $breaks): ?int
    {
        if ($rate === null) {
            return null;
        }

        $class = 0;

        foreach ($breaks as $break) {
            if ($rate > $break) {
                $class++;
            }
        }

        return $class;
    }
}
