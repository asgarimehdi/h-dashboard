<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * Fetch the session-bearing cookie (session or CSRF) from a prepared
 * response. `Response::prepare()` runs in production inside `Response::send()`
 * — feature tests skip it, so it is run here against the actually-bound
 * request to reach the Symfony secure-default derivation.
 */
function withPreparedCookies($response, $request, string $name)
{
    $response->baseResponse->prepare($request);

    return collect($response->baseResponse->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === $name);
}

function sessionCookie(): string
{
    return config('session.cookie');
}

test('session cookie derives its Secure flag from the request scheme when unset', function () {
    // config/session.php passes a null `secure` to Symfony when
    // SESSION_SECURE_COOKIE is unset. Symfony then marks the cookie Secure
    // only when the request itself is HTTPS (direct fastcgi HTTPS, or
    // X-Forwarded-Proto from a proxy pinned in TRUSTED_PROXIES). A fresh
    // server deployed on plain http (`php artisan serve`) therefore keeps a
    // usable, non-Secure cookie — unconditionally-forced Secure (#1003) made
    // browsers drop the cookie over http and login fail with the CSRF
    // "Page Expired" (419) wall. This test pins that startup regression.
    expect(config('session.secure'))->toBeNull()
        ->and(withPreparedCookies($this->get('/login'), $this->app->make('request'), sessionCookie())->isSecure())->toBeFalse();
});

test('session cookie keeps its http_only and same_site defaults (prepared over https)', function () {
    $request = $this->get('https://h-dashboard.test/login');

    $cookie = withPreparedCookies($request, $this->app->make('request'), sessionCookie());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

test('explicit SESSION_SECURE_COOKIE=false opts out (legacy local dev / e2e)', function () {
    config(['session.secure' => false]);

    $cookie = withPreparedCookies($this->get('/login'), $this->app->make('request'), sessionCookie());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeFalse();
});

test('explicit SESSION_SECURE_COOKIE=true forces Secure even over http', function () {
    config(['session.secure' => true]);

    $cookie = withPreparedCookies($this->get('/login'), $this->app->make('request'), sessionCookie());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});

test('derived config becomes Secure over https', function () {
    // A full https:// test URL marks the request itself HTTPS; preparing the
    // response against it flips the derived cookie to Secure — the plain-http
    // artisan-serve deployment can never see this, the direct fastcgi HTTPS
    // deployment always does.
    $request = $this->get('https://h-dashboard.test/login');

    $cookie = withPreparedCookies($request, $this->app->make('request'), sessionCookie());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});

test('derived config becomes Secure behind a pinned TRUSTED_PROXIES proxy (#855)', function () {
    // #855 fail-closed: X-Forwarded-Proto is honored only when the proxy is
    // pinned; with the pin, https makes the request secure and the derived
    // cookie inherits Secure. (This is the startup path of every server that
    // sits behind a terminating load balancer with TRUSTED_PROXIES set.)
    $_ENV['TRUSTED_PROXIES'] = '127.0.0.1';
    $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';
    putenv('TRUSTED_PROXIES=127.0.0.1');

    try {
        $this->refreshApplication();
        $this->withoutVite();

        $cookie = withPreparedCookies(
            $this->get('/login', ['X-Forwarded-Proto' => 'https']),
            $this->app->make('request'),
            sessionCookie()
        );

        expect($cookie)->not->toBeNull()
            ->and($cookie->isSecure())->toBeTrue();
    } finally {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
    }
});

test('derived config ignores an unpinned X-Forwarded-Proto header (#855 fail-closed)', function () {
    // No TRUSTED_PROXIES set: the client-supplied https claim must NOT
    // upgrade the cookie.
    $request = $this->get('/login', ['X-Forwarded-Proto' => 'https']);

    $cookie = withPreparedCookies($request, $this->app->make('request'), sessionCookie());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeFalse();
});

test('config/session.php maps every surviving env spelling to null/true/false', function () {
    foreach ([
        // '' (KEY= in a .env or an env value that became empty) must behave
        // EXACTLY like unset: derive from scheme. A string would otherwise
        // be handed to Symfony's Cookie and silently coerce to false, forcing
        // a non-Secure cookie even on genuinely-https deployments.
        ['value' => '', 'expected' => null],
        ['value' => 'true', 'expected' => true],
        ['value' => 'false', 'expected' => false],
    ] as $case) {
        $_ENV['SESSION_SECURE_COOKIE'] = $case['value'];

        try {
            $sessionConfig = require config_path('session.php');

            expect($sessionConfig['secure'])->toBe($case['expected']);
        } finally {
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            putenv('SESSION_SECURE_COOKIE');
        }
    }
});
