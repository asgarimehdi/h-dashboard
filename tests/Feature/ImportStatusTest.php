<?php

namespace Tests\Feature;

use App\Support\ImportStatus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * #1981: the claim value is the per-dispatch token, so release must be a
 * compare-and-delete. A stale job (whose claim already expired) must never
 * free the claim a newer dispatch took in the meantime.
 *
 * Note on regressions: the stale-token test and the null-token test fail
 * against the old token-blind `release()`. The matching-token test passes
 * against the old code too — it is a regression guard for the new
 * implementation, not a reproduction of the bug.
 *
 * No RefreshDatabase: this exercises the cache only. `Tests\TestCase::setUp()`
 * flushes the cache and `phpunit.xml` forces `CACHE_STORE=array`.
 */
class ImportStatusTest extends TestCase
{
    public function test_release_with_a_stale_token_keeps_the_newer_claim(): void
    {
        $userId = 4242;
        $stale = '4242-stale';
        $fresh = '4242-fresh';

        $this->assertTrue(ImportStatus::claim($userId, $stale));

        // Simulate the old claim expiring before a newer dispatch takes it.
        Cache::forget(ImportStatus::claimKey($userId));

        $this->assertTrue(ImportStatus::claim($userId, $fresh));

        ImportStatus::release($userId, $stale);

        $this->assertSame($fresh, Cache::get(ImportStatus::claimKey($userId)));
        $this->assertFalse(ImportStatus::claim($userId, '4242-third'));
    }

    public function test_release_with_the_matching_token_frees_the_slot(): void
    {
        $userId = 4243;
        $token = '4243-owner';

        $this->assertTrue(ImportStatus::claim($userId, $token));

        ImportStatus::release($userId, $token);

        $this->assertNull(Cache::get(ImportStatus::claimKey($userId)));
        $this->assertTrue(ImportStatus::claim($userId, '4243-next'));
    }

    public function test_release_without_a_token_does_not_free_the_slot(): void
    {
        $userId = 4244;
        $token = '4244-owner';

        $this->assertTrue(ImportStatus::claim($userId, $token));

        ImportStatus::release($userId);

        $this->assertSame($token, Cache::get(ImportStatus::claimKey($userId)));
        $this->assertFalse(ImportStatus::claim($userId, '4244-next'));
    }
}
