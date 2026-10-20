<?php

namespace Tests\Unit;

use App\Support\ScopeCacheKey;
use Tests\TestCase;

covers(ScopeCacheKey::class);

class ScopeCacheKeyTest extends TestCase
{
    public function test_hash_ignores_the_order_of_the_ids(): void
    {
        $this->assertSame(
            ScopeCacheKey::hash([1, 2, 3]),
            ScopeCacheKey::hash([3, 1, 2])
        );

        $this->assertSame(
            ScopeCacheKey::hash([1, 2, 3]),
            ScopeCacheKey::hash([2, 3, 1])
        );
    }

    public function test_hash_treats_numeric_strings_and_ints_alike(): void
    {
        $this->assertSame(
            ScopeCacheKey::hash([1, 2]),
            ScopeCacheKey::hash(['1', '2'])
        );

        $this->assertSame(
            ScopeCacheKey::hash([1, 2]),
            ScopeCacheKey::hash(['2', '1'])
        );
    }

    public function test_hash_of_an_empty_scope_is_the_hash_of_the_empty_list(): void
    {
        // An empty scope is a legitimate answer ("in scope of nothing"), so the
        // helper must return a stable key for it instead of blowing up.
        $this->assertSame(md5(''), ScopeCacheKey::hash([]));
        $this->assertSame(32, strlen(ScopeCacheKey::hash([])));
    }

    public function test_hash_separates_different_scopes(): void
    {
        $this->assertNotSame(
            ScopeCacheKey::hash([1, 2]),
            ScopeCacheKey::hash([1, 3])
        );

        $this->assertNotSame(
            ScopeCacheKey::hash([1, 2]),
            ScopeCacheKey::hash([1, 2, 3])
        );

        // The empty scope must not collide with a scope that holds one null-ish id.
        $this->assertNotSame(
            ScopeCacheKey::hash([]),
            ScopeCacheKey::hash([0])
        );
    }

    public function test_hash_returns_a_hex_md5_digest(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', ScopeCacheKey::hash([7, 8, 9]));
    }
}
