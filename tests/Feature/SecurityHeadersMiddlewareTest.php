<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

covers(SecurityHeaders::class);

uses(TestCase::class);

test('all security headers are present on web responses', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options');
    $response->assertHeader('X-Frame-Options');
    $response->assertHeader('Referrer-Policy');
    $response->assertHeader('Content-Security-Policy-Report-Only');
    $response->assertHeader('Reporting-Endpoints');
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
            // #742: deprecated report-uri replaced by the Reporting API's
            // report-to directive (pointing at Reporting-Endpoints below).
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-to csp-endpoint"
        );
});

test('Reporting-Endpoints header points at the report endpoint (#742)', function () {
    $this->get('/login')->assertHeader('Reporting-Endpoints', 'csp-endpoint="/csp-report"');
});

test('POST /csp-report accepts a browser violation report and logs it', function () {
    Log::spy();

    $payload = [
        'csp-report' => [
            'document-uri' => 'https://dashboard.test/',
            'violated-directive' => "default-src 'self'",
            'blocked-uri' => 'https://evil.example/',
        ],
    ];

    // Browsers send application/csp-report with a raw JSON body.
    $this->call(
        'POST',
        '/csp-report',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/csp-report'],
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    )->assertSuccessful();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context) => $message === 'csp-report'
            && ($context['report'] ?? null) === $payload
    );
});

test('POST /csp-report answers 2xx for JSON and for garbage bodies', function () {
    $this->postJson('/csp-report', ['csp-report' => ['blocked-uri' => 'inline']])->assertSuccessful();

    // Unparseable body must not 500 — the route only ever logs and acks.
    $this->call(
        'POST',
        '/csp-report',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/csp-report'],
        'not json at all'
    )->assertSuccessful();
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
    $response->assertHeaderMissing('Reporting-Endpoints');
    $response->assertHeaderMissing('Strict-Transport-Security');
});
