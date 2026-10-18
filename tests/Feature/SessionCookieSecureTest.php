<?php

use Tests\TestCase;

uses(TestCase::class);

test('session cookie is Secure by default when SESSION_SECURE_COOKIE is unset (#1003)', function () {
    // Locks the config fallback in config/session.php: removing it turns this red.
    expect(config('session.secure'))->toBeTrue();

    $response = $this->get('/login');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});

test('explicit session.secure=false opts out (plaintext local dev / e2e)', function () {
    config(['session.secure' => false]);

    $response = $this->get('/login');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeFalse();
});

test('session cookie keeps its http_only and same_site defaults', function () {
    $response = $this->get('/login');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});
