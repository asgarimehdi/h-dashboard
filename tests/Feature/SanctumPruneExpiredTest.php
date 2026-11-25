<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Console\Commands\PruneExpired;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1820 — /api/login mints a fresh token on every call and never revokes the
 * previous one, and `createToken()` is called without an `expires_at`, so the
 * 24-hour lifetime is enforced by the guard off `created_at`. The daily
 * `sanctum:prune-expired` is what stops `personal_access_tokens` growing
 * forever; these tests pin the shapes production actually writes, including
 * Sanctum's default 24-hour grace.
 */
covers(PruneExpired::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

test('a production-shape token past expiration plus grace is pruned and a live token survives', function () {
    $this->freezeTime();
    ['user' => $user] = $this->createUserWithUnit();

    // /api/login mints with no expires_at; only created_at drives expiry.
    $stale = $user->createToken('flutter-app', ['hardware:read'])->accessToken;
    DB::table('personal_access_tokens')->where('id', $stale->getKey())->update([
        'created_at' => now()->subHours(49),
        'expires_at' => null,
    ]);

    $live = $user->createToken('flutter-app', ['hardware:read'])->accessToken;

    $this->artisan('sanctum:prune-expired')->assertExitCode(0);

    expect(DB::table('personal_access_tokens')->where('id', $stale->getKey())->exists())->toBeFalse();
    expect(DB::table('personal_access_tokens')->where('id', $live->getKey())->exists())->toBeTrue();
});

test('a token exactly on the 48-hour cutoff is kept', function () {
    $this->freezeTime();
    ['user' => $user] = $this->createUserWithUnit();

    $boundary = $user->createToken('flutter-app', ['*'])->accessToken;
    DB::table('personal_access_tokens')->where('id', $boundary->getKey())
        ->update(['created_at' => now()->subHours(47), 'expires_at' => null]);

    $this->artisan('sanctum:prune-expired')->assertExitCode(0);

    expect(DB::table('personal_access_tokens')->where('id', $boundary->getKey())->exists())->toBeTrue();
});

test('the default 24-hour grace keeps a just-expired token', function () {
    $this->freezeTime();
    ['user' => $user] = $this->createUserWithUnit();

    $recent = $user->createToken('flutter-app', ['*'])->accessToken;
    DB::table('personal_access_tokens')->where('id', $recent->getKey())
        ->update(['expires_at' => now()->subHour()]);

    $this->artisan('sanctum:prune-expired')->assertExitCode(0);

    expect(DB::table('personal_access_tokens')->where('id', $recent->getKey())->exists())->toBeTrue();
});

test('a token with expires_at older than the grace is pruned', function () {
    $this->freezeTime();
    ['user' => $user] = $this->createUserWithUnit();

    $expired = $user->createToken('flutter-app', ['*'])->accessToken;
    DB::table('personal_access_tokens')->where('id', $expired->getKey())
        ->update(['expires_at' => now()->subHours(25)]);

    $this->artisan('sanctum:prune-expired')->assertExitCode(0);

    expect(DB::table('personal_access_tokens')->where('id', $expired->getKey())->exists())->toBeFalse();
});
