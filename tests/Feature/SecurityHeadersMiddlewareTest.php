<?php

use App\Http\Middleware\SecurityHeaders;
use Tests\TestCase;

covers(SecurityHeaders::class);

uses(TestCase::class);

test('all five security headers are present on web responses', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options');
    $response->assertHeader('X-Frame-Options');
    $response->assertHeader('Referrer-Policy');
    $response->assertHeader('Content-Security-Policy-Report-Only');
    $response->assertHeader('Strict-Transport-Security');
});

test('X-Content-Type-Options header has correct value', function () {
    $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('X-Frame-Options header has correct value', function () {
    $this->get('/login')->assertHeader('X-Frame-Options', 'DENY');
});

test('Referrer-Policy header has correct value', function () {
    $this->get('/login')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('Content-Security-Policy-Report-Only header has correct value', function () {
    $this->get('/login')
        ->assertHeader(
            'Content-Security-Policy-Report-Only',
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-uri /csp-report"
        );
});

test('Strict-Transport-Security header has correct value', function () {
    $this->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('API responses do NOT include security headers', function () {
    $response = $this->postJson('/api/login', [
        'n_code' => '0000000000',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401);
    $response->assertHeaderMissing('X-Content-Type-Options');
    $response->assertHeaderMissing('X-Frame-Options');
    $response->assertHeaderMissing('Referrer-Policy');
    $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    $response->assertHeaderMissing('Strict-Transport-Security');
});
