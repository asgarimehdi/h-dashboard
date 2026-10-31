<?php

namespace App\Support;

/**
 * Single source of truth for the Content-Security-Policy value (#1281).
 *
 * Phase 1 (enforcing behind `config('csp.enforcing')`):
 *   - `'unsafe-inline'` is gone from `script-src` and `style-src` (nonce carries
 *     them); `'unsafe-eval'` stays — conditionally, pending the CSP-safe build.
 *   - `style-src-attr 'unsafe-inline'` stays: 37 inline `style=` attributes +
 *     the popup strings still need it (phase 2).
 *   - `font-src 'self' data:` — `data:` is a phase-1 exception: the
 *     full-calendar bundle ships a data: font (see the baseline recorded by
 *     tests/e2e/security/csp.spec.ts) and enforcing without it would fail the
 *     zero-violation gate.
 *
 * One nonce per request; connect-src origins come from config('map.*_url').
 */
class CspPolicy
{
    public static function for(string $nonce): string
    {
        $connectOrigins = array_values(array_filter(array_unique([
            self::origin(config('map.routing_url')),
            self::origin(config('map.geocoding_url')),
        ])));

        $parts = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}' 'sha256-BBzkWIzm0YA2BW8GhdAiRj7WWVwUUNJkZzZ5sQBqj54=' 'sha256-C9H3lAsLru1Z0l4uwPBsvav9owaH3ui/wZpBqFhVa6o='",
            // shortcut: Leaflet Draw injects a <style> element at runtime; its
            // hash is pinned here for phase 1. Recompute on Leaflet Draw upgrade.
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            'connect-src '.implode(' ', array_merge(["'self'"], $connectOrigins)),
            "font-src 'self' data:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'report-to csp-endpoint',
        ];

        return implode('; ', $parts);
    }

    /**
     * Turn a URL (config('map.routing_url')) into its scheme+host+port origin,
     * or null when the value is empty/invalid.
     */
    private static function origin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $origin = $parsed['scheme'].'://'.$parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':'.$parsed['port'];
        }

        return $origin;
    }
}
