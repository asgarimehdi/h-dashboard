<?php

namespace App\Support;

/**
 * Order-independent hash of a set of accessible unit ids (issue #1179).
 *
 * The scope of a request is a SET of unit ids, but it does not arrive as one:
 * `Unit::descendantIds()` runs a recursive CTE with no `ORDER BY`, and
 * `AccessService::accessibleUnitIds()` caches that array exactly as it comes
 * back. Two users holding the same scope can therefore receive the same ids in
 * a different order — and every cache key built straight from the array
 * (`md5(implode(',', $ids))`, `md5(json_encode($ids))`) differs between them.
 * The shared cache entry is missed and the same heavy query is paid for again
 * under a second key.
 *
 * Normalising the members to integers and sorting them numerically makes the
 * hash a function of the SET, not of its order.
 *
 * This helper is the one place a scope hash is built. `GisController` is the
 * documented exception: `gisCacheKey()` hashes with `strval` + `SORT_STRING`
 * and its keys must stay as they are.
 */
final class ScopeCacheKey
{
    /**
     * Hash a scope into a cache-key fragment.
     *
     * An empty scope is a legitimate answer ("in scope of nothing"), so it
     * hashes to the digest of the empty list instead of failing.
     *
     * @param  array<int|string>  $ids
     */
    public static function hash(array $ids): string
    {
        $normalized = array_map('intval', $ids);
        sort($normalized, SORT_NUMERIC);

        return md5(implode(',', $normalized));
    }
}
