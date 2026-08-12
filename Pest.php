<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');
uses()->group('browser')->in('Browser');
uses()->parallel();

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// Cache invalidation test helper
expect()->extend('toInvalidateCache', function (string $key) {
    $versionBefore = \Illuminate\Support\Facades\Cache::get($key . '_version', 0);
    $this->value();
    $versionAfter = \Illuminate\Support\Facades\Cache::get($key . '_version', 0);
    return expect($versionAfter)->toBeGreaterThan($versionBefore);
});