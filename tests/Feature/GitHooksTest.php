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
        // A markdown-only commit must not pay for a Pint run.
        foreach (self::HOOKS as $hook) {
            $this->assertStringContainsString('grep', $this->commands($hook));
            $this->assertStringContainsString('.php', $this->commands($hook));
        }
    }
}
