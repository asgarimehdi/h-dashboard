<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
| NOTE: Each Pest-style test file in tests/Feature/ binds its own TestCase
| (and RefreshDatabase where needed) explicitly. Class-based tests extend
| Tests\TestCase directly. NO global TestCase/RefreshDatabase binding here —
| a global `use(RefreshDatabase)` in this file combined with a file-level
| `uses(RefreshDatabase::class)` merged into the SAME trait list (TestRepository
| appends, never dedupes), causing double transaction hooks and a flaky
| `permissions table does not exist` race under random test order.
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something()
{
    // ..
}
