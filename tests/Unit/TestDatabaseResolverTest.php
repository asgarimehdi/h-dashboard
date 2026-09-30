<?php

use App\Support\Testing\TestDatabaseResolver;
use Tests\TestCase;

covers(TestDatabaseResolver::class);

uses(TestCase::class);

/*
 * The effective testing database is not the one in `.env`: phpunit.xml's
 * <env> entries sit between the shell and the app, and — unless they carry
 * force="true" — an exported shell variable silently wins over them. A wrapper
 * that parses phpunit.xml itself would therefore report the wrong database
 * under exactly the conditions it exists to catch, so the resolver replays
 * PHPUnit's own precedence rules and Laravel's own URL parsing.
 */

test('resolves the test database from phpunit.xml when the shell exports nothing', function () {
    $resolver = new TestDatabaseResolver(base_path(), []);

    expect($resolver->resolve()->database)->toBe('h_dashboard_test')
        ->and($resolver->resolve()->driver)->toBe('pgsql');
});

test('an exported DB_DATABASE overrides the phpunit.xml value', function () {
    // phpunit.xml declares DB_DATABASE without force="true", so an exported
    // shell variable replaces it. Without this the suite would run against a
    // database the developer never asked for.
    $resolver = new TestDatabaseResolver(base_path(), ['DB_DATABASE' => 'h_dashboard']);

    expect($resolver->resolve()->database)->toBe('h_dashboard');
});

test('a forced phpunit.xml env entry wins over an exported shell variable', function () {
    $resolver = new TestDatabaseResolver(
        __DIR__.'/../Fixtures/phpunit-forced',
        ['DB_DATABASE' => 'exported_db'],
    );

    expect($resolver->resolve()->database)->toBe('h_dashboard_forced');
});

test('DB_URL wins over every database variable', function () {
    $resolver = new TestDatabaseResolver(base_path(), [
        'DB_URL' => 'pgsql://user:pass@db.example:5433/url_db',
        'DB_DATABASE' => 'h_dashboard',
    ]);

    $resolution = $resolver->resolve();

    expect($resolution->database)->toBe('url_db')
        ->and($resolution->driver)->toBe('pgsql')
        ->and($resolution->host)->toBe('db.example')
        ->and($resolution->port)->toBe(5433);
});

test('falls back to the application configuration when phpunit.xml is absent', function () {
    $resolver = new TestDatabaseResolver(sys_get_temp_dir(), []);

    expect($resolver->resolve()->database)
        ->toBe(config('database.connections.'.config('database.default').'.database'));
});
