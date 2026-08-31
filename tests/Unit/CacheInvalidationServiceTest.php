<?php

namespace Tests\Unit;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

covers(\App\Services\CacheInvalidationService::class);

class CacheInvalidationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function service(): CacheInvalidationServiceInterface
    {
        return app(CacheInvalidationServiceInterface::class);
    }

    public function test_increment_bumps_version(): void
    {
        $svc = $this->service();

        $this->assertEquals(0, $svc->getVersion('test_ns'));
        $svc->increment('test_ns');
        $this->assertEquals(1, $svc->getVersion('test_ns'));
        $svc->increment('test_ns');
        $this->assertEquals(2, $svc->getVersion('test_ns'));
    }

    public function test_different_namespaces_are_isolated(): void
    {
        $svc = $this->service();

        $svc->increment('alpha');
        $svc->increment('alpha');
        $svc->increment('beta');

        $this->assertEquals(2, $svc->getVersion('alpha'));
        $this->assertEquals(1, $svc->getVersion('beta'));
        $this->assertEquals(0, $svc->getVersion('gamma'));
    }

    public function test_cache_key_format(): void
    {
        $svc = $this->service();
        $svc->increment('gis');

        $key = $svc->cacheKey('gis', 'abc123', 'all');
        $this->assertStringContainsString('gis:v1:abc123:all', $key);
    }

    public function test_cache_key_changes_after_increment(): void
    {
        $svc = $this->service();
        $svc->increment('gis');

        $key1 = $svc->cacheKey('gis', 'scope1', 'all');
        $svc->increment('gis');
        $key2 = $svc->cacheKey('gis', 'scope1', 'all');

        $this->assertNotEquals($key1, $key2);
    }

    public function test_remember_stores_and_retrieves(): void
    {
        $svc = $this->service();

        $result = $svc->remember('test_remember', 'scope1', fn () => ['data' => 'hello'], 10);

        $this->assertEquals(['data' => 'hello'], $result);

        // Second call should hit cache
        $cached = $svc->remember('test_remember', 'scope1', fn () => ['data' => 'different'], 10);
        $this->assertEquals(['data' => 'hello'], $cached);
    }

    public function test_remember_uses_extra_in_key(): void
    {
        $svc = $this->service();

        $svc->remember('gis', 'scope1', fn () => ['endpoint' => 'units'], 60, ['zoom' => 5]);
        $key1 = $svc->cacheKey('gis', 'scope1', md5(serialize(['zoom' => 5])));

        $svc->remember('gis', 'scope1', fn () => ['endpoint' => 'units'], 60, ['zoom' => 10]);
        $key2 = $svc->cacheKey('gis', 'scope1', md5(serialize(['zoom' => 10])));

        $this->assertNotEquals($key1, $key2);
    }

    public function test_remember_returns_closures_result(): void
    {
        $svc = $this->service();
        $calls = 0;

        $result = $svc->remember('expensive', 's1', function () use (&$calls) {
            $calls++;

            return ['computed' => true];
        }, 5);

        $this->assertEquals(['computed' => true], $result);
        $this->assertEquals(1, $calls);

        // Cache hit — closure not called again
        $svc->remember('expensive', 's1', function () use (&$calls) {
            $calls++;

            return ['computed' => false];
        }, 5);

        $this->assertEquals(1, $calls);
    }
}
