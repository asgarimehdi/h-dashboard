<?php

use App\Support\CspPolicy;
use Tests\TestCase;

uses(TestCase::class);

describe('CspPolicy', function () {
    it('embeds the nonce in script-src and style-src', function () {
        $csp = CspPolicy::for('NONCE123');
        expect($csp)->toContain("script-src 'self' 'unsafe-eval' 'nonce-NONCE123'");
        expect($csp)->toContain("style-src 'self' 'nonce-NONCE123'");
        expect($csp)->toContain("style-src-attr 'unsafe-inline'");
    });

    it('connect-src carries routing and geocoding origins from config', function () {
        config(['map.routing_url' => 'http://127.0.0.1:5000']);
        config(['map.geocoding_url' => 'http://127.0.0.1:8088']);
        $csp = CspPolicy::for('N');
        expect($csp)->toContain('connect-src \'self\' http://127.0.0.1:5000 http://127.0.0.1:8088');
    });

    it('omits empty/invalid origins', function () {
        config(['map.routing_url' => '']);
        config(['map.geocoding_url' => 'not a url']);
        $csp = CspPolicy::for('N');
        expect($csp)->toContain("connect-src 'self'");
        expect($csp)->not->toContain('not a url');
    });
});
