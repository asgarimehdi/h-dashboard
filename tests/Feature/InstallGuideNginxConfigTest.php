<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1181 — the nginx site the install guide ships.
 *
 * `install-guid.md` §8 is the ONLY copy of the server block: there is no
 * `deploy/nginx/` directory in this repository, so the guide IS the config
 * every operator pastes into `/etc/nginx/sites-available/`.
 *
 * Two nginx facts drive every assertion here, and both were reproduced with a
 * real nginx 1.24 serving the repo's own `public/` directory:
 *
 *  1. `add_header` does NOT inherit. A `location` that declares its own
 *     `add_header` discards every `add_header` of the enclosing server
 *     block. The plan's own location blocks therefore stripped
 *     `X-Content-Type-Options` and `X-Frame-Options` from EVERY static asset
 *     — and the PHP `SecurityHeaders` middleware cannot cover them, because
 *     assets never pass through PHP. Each location that declares an
 *     `add_header` must repeat both security headers.
 *
 *  2. `expires` and `add_header Cache-Control` together emit TWO
 *     `Cache-Control` headers. The hashed location must therefore use
 *     `add_header ... always;` and no `expires`.
 *
 * The assertions read the SHIPPED DIRECTIVES, so a reviewer can look at the
 * guide and the test side by side. nginx comments are stripped first: the
 * guide explains each decision in prose, and a comment that merely MENTIONS
 * `immutable` or `woff2` must not be able to satisfy — or break — a rule
 * about the directives themselves. The behavioural proof (nginx -t and three
 * `curl -sI` runs) is reported in the pull request.
 */
class InstallGuideNginxConfigTest extends TestCase
{
    /**
     * The `server { … }` block the guide ships, fenced as ```nginx, with the
     * nginx comments removed.
     */
    private function serverBlock(): string
    {
        $guide = file_get_contents(base_path('install-guid.md'));

        $this->assertIsString($guide, 'install-guid.md must be readable');

        $this->assertSame(
            1,
            preg_match_all('/^```nginx$/m', $guide),
            'the guide must ship exactly one nginx block — two would make the tested config ambiguous'
        );

        $this->assertSame(
            1,
            preg_match('/^```nginx\n(.*?)^```/ms', $guide, $matches),
            'the nginx block must be a fenced code block'
        );

        return $this->stripComments($matches[1]);
    }

    /**
     * Drop nginx comments (`#` to the end of the line).
     *
     * The block quotes no string containing a `#`, so the naive rule is the
     * nginx rule here.
     */
    private function stripComments(string $source): string
    {
        return (string) preg_replace('/#[^\n]*/', '', $source);
    }

    /**
     * The body of one `location` block, braces balanced.
     *
     * A plain `strpos`/`strstr` pair would break on the first `}` inside the
     * block, so the braces are counted.
     */
    private function locationBody(string $serverBlock, string $opener): string
    {
        $start = strpos($serverBlock, $opener);

        $this->assertNotFalse($start, "the guide has no `{$opener}` block");

        $depth = 0;
        $length = strlen($serverBlock);

        for ($i = $start + strlen($opener) - 1; $i < $length; $i++) {
            if ($serverBlock[$i] === '{') {
                $depth++;
            } elseif ($serverBlock[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($serverBlock, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("the `{$opener}` block is never closed");
    }

    public function test_compression_is_enabled_at_server_level(): void
    {
        $block = $this->serverBlock();

        // A directive, not a mention: `^\s*gzip on;$` cannot be satisfied by a
        // line that merely talks about gzip.
        $this->assertMatchesRegularExpression('/^\s*gzip on;$/m', $block);
        $this->assertMatchesRegularExpression('/^\s*gzip_vary on;$/m', $block);
        $this->assertMatchesRegularExpression('/^\s*gzip_comp_level 5;$/m', $block);
        $this->assertMatchesRegularExpression('/^\s*gzip_min_length 1024;$/m', $block);

        $this->assertMatchesRegularExpression('/^\s*gzip_types\s*$/m', $block);
    }

    public function test_gzip_types_leave_out_the_already_compressed_woff2(): void
    {
        $block = $this->serverBlock();

        // The value of the `gzip_types` directive, and nothing else.
        $this->assertSame(
            1,
            preg_match('/^\s*gzip_types\s*(.*?);/ms', $block, $matches),
            'the guide must declare a gzip_types list'
        );

        $types = $matches[1];

        // woff2 is already compressed; gzipping it burns CPU for nothing.
        $this->assertStringNotContainsString('woff2', $types);

        // ...while the types that are actually worth compressing stay listed.
        $this->assertStringContainsString('text/css', $types);
        $this->assertStringContainsString('application/javascript', $types);
        $this->assertStringContainsString('image/svg+xml', $types);
    }

    public function test_hashed_build_assets_are_cached_immutably(): void
    {
        $block = $this->serverBlock();

        $this->assertStringContainsString('location ^~ /build/assets/', $block);

        $location = $this->locationBody($block, 'location ^~ /build/assets/');

        // ONE Cache-Control header, with `always` so it is sent on non-2xx
        // responses too.
        $this->assertMatchesRegularExpression(
            '/add_header\s+Cache-Control\s+"public,\s*max-age=31536000,\s*immutable"\s+always;/',
            $location
        );
    }

    public function test_the_hashed_location_does_not_also_use_expires(): void
    {
        $block = $this->serverBlock();

        $location = $this->locationBody($block, 'location ^~ /build/assets/');

        // `expires` emits its own `Cache-Control: max-age=…`; combined with
        // `add_header Cache-Control` the response carries TWO of them.
        $this->assertStringNotContainsString('expires', $location);
    }

    public function test_the_hashed_location_repeats_both_security_headers(): void
    {
        $block = $this->serverBlock();

        $location = $this->locationBody($block, 'location ^~ /build/assets/');

        // The whole point of repeating them: `add_header` does not inherit, so
        // without the repetition these assets would ship with no security
        // headers at all — and the PHP middleware never sees them.
        $this->assertStringContainsString('add_header X-Content-Type-Options nosniff;', $location);
        $this->assertStringContainsString('add_header X-Frame-Options SAMEORIGIN;', $location);
    }

    public function test_immutable_is_reserved_for_the_hashed_assets(): void
    {
        $block = $this->serverBlock();

        $hashed = $this->locationBody($block, 'location ^~ /build/assets/');

        // Everything OUTSIDE the hashed location.
        $rest = str_replace($hashed, '', $block);

        $this->assertStringNotContainsString('immutable', $rest);
    }

    public function test_unhashed_asset_paths_get_a_short_ttl_without_immutable(): void
    {
        $block = $this->serverBlock();

        $this->assertStringContainsString('location ~* ^/(?:js|css|icons|fonts)/', $block);

        $location = $this->locationBody($block, 'location ~* ^/(?:js|css|icons|fonts)/');

        // These paths are NOT content-hashed, so a deploy can replace their
        // content under the same URL: a bounded TTL, and no `immutable`.
        $this->assertStringContainsString('expires 1d;', $location);
        $this->assertStringNotContainsString('immutable', $location);
    }

    public function test_the_unhashed_location_repeats_both_security_headers(): void
    {
        $block = $this->serverBlock();

        $location = $this->locationBody($block, 'location ~* ^/(?:js|css|icons|fonts)/');

        $this->assertStringContainsString('add_header X-Content-Type-Options nosniff;', $location);
        $this->assertStringContainsString('add_header X-Frame-Options SAMEORIGIN;', $location);
    }

    public function test_the_server_block_still_carries_the_security_headers(): void
    {
        $block = $this->serverBlock();

        // The server-level pair is what the locations repeat; dropping it
        // would leave every other response unprotected.
        $this->assertStringContainsString('add_header X-Content-Type-Options nosniff;', $block);
        $this->assertStringContainsString('add_header X-Frame-Options SAMEORIGIN;', $block);
    }

    public function test_the_guide_tells_the_operator_to_reload_nginx(): void
    {
        $guide = file_get_contents(base_path('install-guid.md'));

        $this->assertIsString($guide, 'install-guid.md must be readable');

        // Editing the file changes nothing on a running server: applying the
        // config is the operator's job, and the guide has to say so.
        $this->assertStringContainsString('nginx -s reload', $guide);
    }
}
