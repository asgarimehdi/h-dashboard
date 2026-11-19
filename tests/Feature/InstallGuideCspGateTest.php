<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #1597 — the CSP go-live gate the install guide has to carry.
 *
 * #1281 delivered the switch and the per-request nonce, #1352 removed the
 * `unsafe-*` sources and added the blocking `csp-enforcing` CI job. Neither
 * changes anything for an operator who follows `install-guid.md`: the guide
 * copies `.env.example.pgsql`, whose `CSP_ENFORCING` default keeps the app on
 * `Content-Security-Policy-Report-Only` — reporting, never blocking — so the
 * missing step was discoverability, not the policy.
 *
 * The decision recorded on the issue (expert comment 7012609528, gated PASS in
 * 7013001152) is that the DEFAULT STAYS `false`; the guide and the template the
 * guide copies get fixed instead. A default flip would be a silent posture
 * change on upgrade for every deployment that never set the key, while the CI
 * gate only proves the reference config on the pages it walks.
 *
 * Like `InstallGuideNginxConfigTest`, this reads the SHIPPED text: the guide is
 * the only copy of these instructions, so an unread paragraph is the failure
 * mode. Assertions are anchored to directives/commands inside the relevant
 * section, so prose that merely mentions a key cannot satisfy them.
 */
class InstallGuideCspGateTest extends TestCase
{
    private function guide(): string
    {
        $guide = file_get_contents(base_path('install-guid.md'));

        $this->assertIsString($guide, 'install-guid.md must be readable');

        return $guide;
    }

    /**
     * The text between two level-2 headings, exclusive of the later one.
     */
    private function section(string $from, string $to): string
    {
        $guide = $this->guide();

        $start = strpos($guide, $from);
        $this->assertNotFalse($start, "install-guid.md has no `{$from}` heading");

        $end = strpos($guide, $to, $start);
        $this->assertNotFalse($end, "install-guid.md has no `{$to}` heading after `{$from}`");

        return substr($guide, $start, $end - $start);
    }

    private function goLiveSection(): string
    {
        return $this->section('## 13. ', '## Troubleshooting');
    }

    private function environmentSection(): string
    {
        return $this->section('## 6. ', '## 7. ');
    }

    public function test_the_go_live_checklist_sits_between_verify_and_troubleshooting(): void
    {
        $guide = $this->guide();

        $verify = strpos($guide, '## 12. ');
        $goLive = strpos($guide, '## 13. Go-live checklist (production)');
        $troubleshooting = strpos($guide, '## Troubleshooting');

        $this->assertNotFalse($verify);
        $this->assertNotFalse($goLive, 'the production go-live checklist is missing');
        $this->assertNotFalse($troubleshooting);

        $this->assertTrue(
            $verify < $goLive && $goLive < $troubleshooting,
            '§13 must come after §12 (Verify) and before Troubleshooting'
        );
    }

    public function test_the_flip_sets_enforcing_true(): void
    {
        $section = $this->goLiveSection();

        // A `.env` assignment on its own line, not a mention.
        $this->assertMatchesRegularExpression('/^CSP_ENFORCING=true$/m', $section);
    }

    public function test_the_flip_rebuilds_the_cached_config(): void
    {
        $section = $this->goLiveSection();

        // Without the rebuild the new env value never reaches the running app,
        // so the operator "verifies" the old mode and nothing changes.
        $this->assertStringContainsString('php artisan config:clear && php artisan optimize', $section);
        $this->assertStringContainsString('§10', $section);
    }

    public function test_the_header_check_hits_a_200_web_route_and_demands_the_enforcing_header(): void
    {
        $section = $this->goLiveSection();

        // `/` answers with the auth 302, produced before SecurityHeaders runs,
        // so it carries none of the six headers — `/login` is the 200 route.
        $this->assertMatchesRegularExpression(
            "/curl -sI [^\\n]*\\/login[^\\n]*grep -i '\\^content-security-policy:'/",
            $section
        );

        // The enforcing header is the pass condition...
        $this->assertStringContainsString('content-security-policy:', $section);

        // ...and the report-only one is named only to be ruled out. No command
        // may grep for it as if it were the expected output.
        $this->assertStringContainsString('content-security-policy-report-only:', $section);
        $this->assertMatchesRegularExpression('/never/', $section);
        $this->assertDoesNotMatchRegularExpression('/grep[^\n]*content-security-policy-report-only/i', $section);
    }

    public function test_the_rollback_line_returns_to_report_only(): void
    {
        $section = $this->goLiveSection();

        $this->assertMatchesRegularExpression('/rollback/i', $section);
        $this->assertStringContainsString('CSP_ENFORCING=false', $section);
    }

    public function test_the_soak_walks_the_pages_the_ci_gate_covers_and_reads_the_report_log(): void
    {
        $section = $this->goLiveSection();

        foreach (['/login', '/dashboard', '/map', '/maps/route2', '/hr-dashboard', '/tickets/inbox'] as $page) {
            $this->assertStringContainsString($page, $section, "the soak must walk {$page}");
        }

        // The log is the cheap early signal; the console walk is the proof.
        $this->assertStringContainsString("grep 'csp-report' storage/logs/laravel.log", $section);
        $this->assertStringContainsString('report-uri', $section);
    }

    public function test_the_section_forces_a_choice_between_enforcing_and_a_dated_deferral(): void
    {
        $section = $this->goLiveSection();

        // Both outcomes terminate the section — enforce now, or defer with a
        // record. A reader must not be able to finish it having chosen neither.
        $this->assertMatchesRegularExpression('/enforce at handoff/i', $section);
        $this->assertMatchesRegularExpression('/cannot enforce at handoff/i', $section);

        // The obligation lives in the DEPLOYMENT's ops log, never as a
        // repo-level date: `beta` itself has no deployment, so a repo date
        // would be fiction, and self-hosted installs have their own timeline.
        $this->assertStringContainsString('ops log', $section);
        $this->assertMatchesRegularExpression('/reason/', $section);
        $this->assertMatchesRegularExpression('/owner/', $section);
        $this->assertMatchesRegularExpression('/date/', $section);
        $this->assertMatchesRegularExpression('/re-run\s+13\.1/', $section);
    }

    public function test_the_session_cookie_instruction_is_conditional(): void
    {
        $guide = $this->guide();
        $goLive = $this->goLiveSection();

        // Per C3: forcing `Secure` on the guide's own plain-`http` nginx block
        // reproduces the #1003 419 "Page Expired" login wall. The guide never
        // ships it as an unconditional assignment.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*SESSION_SECURE_COOKIE=true\s*$/m',
            $guide,
            'SESSION_SECURE_COOKIE=true must only ever appear as forced, conditional guidance'
        );

        foreach ([$this->environmentSection(), $goLive] as $section) {
            $this->assertStringContainsString('SESSION_SECURE_COOKIE', $section);
            $this->assertStringContainsString('TRUSTED_PROXIES', $section);
            $this->assertMatchesRegularExpression('/unset/i', $section);
            $this->assertMatchesRegularExpression('/only/i', $section);
        }
    }

    public function test_the_environment_block_offers_the_key_beside_a_pointer_to_the_gate(): void
    {
        $section = $this->environmentSection();

        // §6 copies `.env.example.pgsql`: an operator who never sees the knob
        // never flips it.
        $this->assertMatchesRegularExpression('/^CSP_ENFORCING=false\b/m', $section);
        $this->assertMatchesRegularExpression('/§13/', $section);
    }

    public function test_every_env_template_exposes_the_key_at_its_report_only_default(): void
    {
        // The guide copies the pgsql template; mysql carries it for parity.
        // `.env.example` already had it and must not drift either.
        foreach (['.env.example.pgsql', '.env.example.mysql', '.env.example'] as $template) {
            $contents = file_get_contents(base_path($template));

            $this->assertIsString($contents, "{$template} must be readable");
            $this->assertMatchesRegularExpression(
                '/^CSP_ENFORCING=false$/m',
                $contents,
                "{$template} must expose CSP_ENFORCING at the report-only default"
            );
            $this->assertStringNotContainsString(
                'CSP_ENFORCING=true',
                $contents,
                "{$template} must not ship the enforcing mode as a default"
            );
        }
    }

    public function test_the_config_default_is_not_flipped(): void
    {
        $config = file_get_contents(base_path('config/csp.php'));

        $this->assertIsString($config, 'config/csp.php must be readable');

        // The gated decision: fix discoverability, never the default. A flip
        // here would silently change the posture of every deployment that
        // never set the key, on its next `git pull`.
        $this->assertStringContainsString("env('CSP_ENFORCING', false)", $config);
    }
}
