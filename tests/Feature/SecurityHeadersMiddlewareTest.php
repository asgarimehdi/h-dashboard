<?php

use App\Http\Middleware\SecurityHeaders;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(SecurityHeaders::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

// Livewire v4 hoists @assets content via static props ($renderedAssets,
// $alreadyRunAssetKeys) and an instance flag (hasRenderedStyles) that do NOT
// reset between two in-process HTTP requests in a test — route2's head assets
// would leak into the units.map render and make the C6 assertions vacuous.
// Same hygiene as flush-state, which only fires per Livewire request.
beforeEach(function () {
    SupportScriptsAndAssets::$renderedAssets = [];
    SupportScriptsAndAssets::$alreadyRunAssetKeys = [];
    SupportScriptsAndAssets::$nonLivewireAssets = [];
    $frontend = app(FrontendAssets::class);
    $frontend->hasRenderedStyles = false;
    $frontend->hasRenderedScripts = false;
});

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

test('Content-Security-Policy-Report-Only header has correct directive set with nonce', function () {
    $response = $this->get('/login');

    // #1281: the nonce is per-request, so the exact string cannot be pinned.
    // Assert the directive set instead (see cspDirectives() below).
    $csp = (string) $response->baseResponse->headers->get('Content-Security-Policy-Report-Only');
    $directives = cspDirectives($csp);

    expect($directives['default-src'])->toContain("'self'");
    expect($directives['script-src'])->toContain("'self'");
    expect($directives['style-src'])->toContain("'self'");
    // #1352 PR 2 (M2 pin): the single-source policy is pinned by test, not
    // just by reading the literal — no `unsafe-inline` in script/style-src
    // and no `unsafe-eval` anywhere in the emitted policy.
    expect($directives['script-src'])->not->toContain("'unsafe-inline'");
    expect($directives['style-src'])->not->toContain("'unsafe-inline'");
    expect($directives['script-src'])->not->toContain("'unsafe-eval'");
    expect($directives['report-to'])->toContain('csp-endpoint');
});

/**
 * CSP header test helper: parses the policy string into
 * [directive => list-of-values] arrays.
 */
function cspDirectives(string $csp): array
{
    $out = [];
    foreach (explode(';', $csp) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $tokens = preg_split('/\s+/', $part);
        $out[$tokens[0]] = array_values(array_slice($tokens, 1));
    }

    return $out;
}

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
    $response->assertHeaderMissing('Content-Security-Policy');
    $response->assertHeaderMissing('Reporting-Endpoints');
    $response->assertHeaderMissing('Strict-Transport-Security');
});

// ── #1281: per-request CSP nonce ──────────────────────────────────────────

test('CSP nonce is per-request: embedded in the policy and unique across two requests', function () {
    $first = $this->get('/login');
    $second = $this->get('/login');

    $extract = fn (?string $csp): ?string => preg_match("/'nonce-([^']+)'/", (string) $csp, $m) ? $m[1] : null;

    $nonce1 = $extract($first->baseResponse->headers->get('Content-Security-Policy-Report-Only'));
    $nonce2 = $extract($second->baseResponse->headers->get('Content-Security-Policy-Report-Only'));

    expect($nonce1)->toBeString();
    expect($nonce2)->toBeString();
    // Nonces are 16 random bytes, base64 — definitely not equal between requests.
    expect($nonce2)->not->toBe($nonce1);
});

test('head tags with an inline payload carry the CSP nonce from the header (#1281 rendered proof, C6)', function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();
    $result = $this->createUserWithUnit(['map', 'organization']);
    $this->actingAs($result['user']);

    // route2 hoists same-origin `<script src>` tags only; units.map's own
    // `<style>` block is compiled away by Livewire's SFC compiler into a CSS
    // module (covered by `style-src 'self'`). Both pages prove the C6 chain —
    // CSP does not care about head vs body: every INLINE script/style tag must
    // carry the request nonce, while same-origin src/href tags and the CSS
    // module links deliberately stay nonce-less (C6).
    foreach (['/maps/route2', '/units/'.$result['unit']->id.'/map'] as $uri) {
        $response = $this->get($uri);
        $response->assertOk();

        $csp = (string) $response->baseResponse->headers->get('Content-Security-Policy-Report-Only');
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $headerNonce = $m[1] ?? null;
        expect($headerNonce)->toBeString();

        $html = (string) $response->getContent();
        preg_match_all('#<(script|style)\b[^>]*>#', $html, $tags);
        $inlineTags = array_values(array_filter(
            $tags[0],
            fn ($tag) => ! str_contains(strtolower($tag), ' src='),
        ));

        // A page without any inline script/style would make the sweep vacuous;
        // Livewire's own <style data-livewire-style> is always on the page.
        $noncedInlineStyles = array_filter(
            $inlineTags,
            fn ($t) => str_starts_with($t, '<style') && str_contains($t, 'nonce='),
        );
        expect(count($noncedInlineStyles))->toBeGreaterThan(0);

        foreach ($inlineTags as $tag) {
            expect($tag)->toContain('nonce="'.$headerNonce.'"');
        }
    }
});
