<?php

namespace Tests\Feature;

use Tests\TestCase;

covers(GitHooksTest::class);

/*
 * The hooks are versioned under .githooks/ and activated with core.hooksPath,
 * because composer's post-install-cmd symlink only appears on a fresh
 * `composer install`: on an already-provisioned machine the light pre-commit
 * layer is silently absent, and nobody notices until CI rejects the format.
 *
 * Every assertion below runs against the hook's EXECUTABLE lines only —
 * comments are stripped first. Both hooks explain in prose why they do not run
 * something (that is the whole strategy, written down where the next person
 * will read it), and a grep that counted those words would pass a hook that
 * runs exactly what it promises not to.
 */
class GitHooksTest extends TestCase
{
    private const HOOKS = ['pre-commit', 'pre-push'];

    private function hookPath(string $hook): string
    {
        return base_path(".githooks/{$hook}");
    }

    /**
     * The hook's commands, with comments and blank lines removed.
     */
    private function commands(string $hook): string
    {
        $lines = file($this->hookPath($hook), FILE_IGNORE_NEW_LINES) ?: [];

        $commands = array_filter(
            array_map(trim(...), $lines),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#')
        );

        return implode("\n", $commands);
    }

    public function test_both_hooks_are_versioned(): void
    {
        foreach (self::HOOKS as $hook) {
            $this->assertFileExists($this->hookPath($hook), ".githooks/{$hook} must be committed");
        }
    }

    public function test_both_hooks_are_executable(): void
    {
        // core.hooksPath only runs files git considers executable; a hook that
        // lost its bit during a copy is a hook that silently never fires.
        foreach (self::HOOKS as $hook) {
            $this->assertTrue(
                is_executable($this->hookPath($hook)),
                ".githooks/{$hook} must be executable"
            );
        }
    }

    public function test_the_pre_commit_hook_runs_pint_on_staged_files_only(): void
    {
        $commands = $this->commands('pre-commit');

        $this->assertMatchesRegularExpression('/\bpint\b/', $commands);
        $this->assertStringContainsString('diff --cached', $commands);
    }

    public function test_the_pre_commit_hook_never_runs_phpstan_or_the_suite(): void
    {
        // The three-layer strategy rests on the light layer staying light: a
        // pre-commit that runs PHPStan or the suite is a hook the team starts
        // bypassing with --no-verify, which is worse than no hook.
        $commands = $this->commands('pre-commit');

        $this->assertStringNotContainsString('phpstan', $commands);
        $this->assertStringNotContainsString('artisan test', $commands);
        $this->assertStringNotContainsString('pest', $commands);
        $this->assertStringNotContainsString('composer test', $commands);
    }

    public function test_the_pre_push_hook_runs_the_whole_phpstan_analysis(): void
    {
        // Full analysis, not the staged subset: a staged-only path would be an
        // analysis route CI never exercises, so errors that surface only through
        // dependencies would never be seen locally.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('phpstan analyse', $commands);
        $this->assertStringNotContainsString('phpstan analyse $', $commands);
    }

    public function test_the_pre_push_hook_never_runs_the_full_suite_unasked(): void
    {
        // The suite takes minutes; on push that is a push people cancel or
        // bypass. Tests are opt-in, named by the developer — never guessed,
        // because a heuristic can report green without running the failing test.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('VERIFY_TESTS', $commands);
        $this->assertStringNotContainsString('composer verify', $commands);
    }

    public function test_the_pre_push_hook_runs_the_suite_only_when_explicitly_named(): void
    {
        // The opt-in branch is the only path to the suite, and it is guarded by
        // a non-empty check rather than by counting arguments.
        $commands = $this->commands('pre-push');

        $this->assertMatchesRegularExpression('/if \[ -n "?\$\{VERIFY_TESTS:\-\}"? \]/', $commands);
    }

    public function test_the_hooks_ignore_non_php_changes(): void
    {
        // A markdown-only commit must not pay for a Pint run, and a docs-only
        // push must not pay for PHPStan. The pre-commit hook filters with grep
        // (it builds an explicit file list); the pre-push hook lets git do it
        // with a pathspec, which is the same guarantee without the extra step.
        $this->assertStringContainsString('grep', $this->commands('pre-commit'));
        $this->assertStringContainsString('.php', $this->commands('pre-commit'));

        $this->assertStringContainsString("'*.php'", $this->commands('pre-push'));
    }

    public function test_the_pre_push_hook_asks_git_which_refs_are_being_pushed(): void
    {
        // The index is ALWAYS empty when a pre-push hook runs, so a gate built
        // on `git diff --cached` reports nothing and silently never runs — the
        // gate that looks installed and never fires. git passes the refs on
        // stdin; the hook has to read them.
        $commands = $this->commands('pre-push');

        $this->assertStringNotContainsString('diff --cached', $commands);
        $this->assertMatchesRegularExpression('/while read /', $commands);
        $this->assertStringContainsString('pushed_php_files', $commands);
    }

    public function test_the_pre_push_hook_compares_against_the_remote_sha(): void
    {
        // The decision has to be made about the range actually being pushed, not
        // about the working tree — otherwise a docs-only push still pays for
        // PHPStan and a code push on an "empty" index skips it.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('..HEAD', $commands);
        $this->assertStringContainsString('0000000000000000000000000000000000000000', $commands);
    }

    // ──────────────────────────────────────────────
    // Issue #1886 — the JS gates ride the pre-push layer when JS changed
    // ──────────────────────────────────────────────

    public function test_the_pre_push_hook_runs_the_js_gates_when_js_changed(): void
    {
        // CI's `js-lint` and `js-typecheck` jobs are blocking; before #1886 a
        // JS-only change passed every local gate and failed only in CI. The hook
        // asks git the same range question it asks for PHP, over the JS paths.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('resources/js/*.js', $commands);
        $this->assertStringContainsString('package-lock.json', $commands);
        $this->assertStringContainsString('js-gates.sh', $commands);
    }

    public function test_the_pre_push_hook_never_installs_node_modules(): void
    {
        // `npm ci` deletes node_modules and needs the registry: mid-push that
        // breaks a running vite, and offline it blocks the push entirely. The
        // shared script skips loudly instead, pointing at `composer verify`.
        $commands = $this->commands('pre-push');

        $this->assertStringNotContainsString('npm ci', $commands);
        $this->assertStringContainsString('--no-install', $commands);
    }

    public function test_the_pre_push_hook_still_skips_phpstan_only_for_php_absence(): void
    {
        // Adding the JS branch must not turn a PHP-less push into a PHPStan run:
        // the message and the skip are exactly what they were before #1886.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('No PHP changes', $commands);
    }

    public function test_the_js_gates_script_matches_the_ci_steps(): void
    {
        // Drift guard: the four blocking commands live in one script, and that
        // script must keep saying what the two CI jobs say. A command that
        // changes in the workflow and not here is the bug #1886 is about.
        $script = (string) file_get_contents(base_path('scripts/js-gates.sh'));
        $workflow = (string) file_get_contents(base_path('.github/workflows/test.yml'));

        foreach (['npm run lint', 'npm run format:check'] as $step) {
            $this->assertStringContainsString($step, $script);
            $this->assertStringContainsString($step, $workflow);
        }

        $this->assertStringContainsString('tsc -p tsconfig.js.json --noEmit', $script);
        $this->assertStringContainsString('tsc -p tsconfig.js.json --noEmit', $workflow);

        $ratchet = "grep -L '^// @ts-check' resources/js/*.js";
        $this->assertStringContainsString($ratchet, $script);
        $this->assertStringContainsString($ratchet, $workflow);
    }
}
