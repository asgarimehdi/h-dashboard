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

    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            exec('rm -rf '.escapeshellarg($sandbox));
        }

        $this->sandboxes = [];

        parent::tearDown();
    }

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
    // Issue #1997 — the first push of a new branch must not skip PHPStan
    // ──────────────────────────────────────────────

    public function test_the_pre_push_hook_resolves_a_new_branch_from_the_merge_base(): void
    {
        // A new branch has no remote sha; the gate has to diff against the
        // base branch instead of returning an empty list (which means "skip").
        // The fallback must be a non-empty file list, so `ls-files`, not `return 0`.
        $commands = $this->commands('pre-push');

        $this->assertStringContainsString('merge-base', $commands);
        $this->assertStringContainsString('ls-files', $commands);
        $this->assertStringContainsString('beta', $commands);
    }

    public function test_the_first_push_of_a_new_branch_runs_phpstan(): void
    {
        [$repo, $base] = $this->makeRepo();

        $this->git($repo, 'update-ref', 'refs/remotes/origin/beta', $base);
        $this->git($repo, 'checkout', '-q', '-b', 'feature');
        $this->commitFile($repo, 'app/Foo.php');

        [$exit, $output] = $this->runHook($repo, $this->newBranchStdin($repo, 'feature'));

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($repo.'/phpstan.ran', 'a new branch with PHP changes must run PHPStan');
        $this->assertStringContainsString('PHPStan (full analysis)', $output);
        $this->assertStringNotContainsString('skipping PHPStan', $output);
    }

    public function test_a_docs_only_new_branch_still_skips_phpstan(): void
    {
        // The fix must not turn every new branch into a full analysis: a
        // docs-only branch has nothing for PHPStan to see.
        [$repo, $base] = $this->makeRepo();

        $this->git($repo, 'update-ref', 'refs/remotes/origin/beta', $base);
        $this->git($repo, 'checkout', '-q', '-b', 'docs-only');
        $this->commitFile($repo, 'docs/note.md');

        [$exit, $output] = $this->runHook($repo, $this->newBranchStdin($repo, 'docs-only'));

        $this->assertSame(0, $exit, $output);
        $this->assertFileDoesNotExist($repo.'/phpstan.ran');
        $this->assertStringContainsString('skipping PHPStan', $output);
    }

    public function test_a_new_branch_without_a_base_ref_analyses_every_tracked_php_file(): void
    {
        // No origin/beta and no upstream/beta (a fork that never fetched beta):
        // the safe fallback is every tracked PHP file, never an empty list.
        [$repo] = $this->makeRepo();

        $this->git($repo, 'checkout', '-q', '-b', 'feature');
        $this->commitFile($repo, 'app/Foo.php');

        [$exit, $output] = $this->runHook($repo, $this->newBranchStdin($repo, 'feature'));

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($repo.'/phpstan.ran', 'the fallback must run PHPStan, not skip it');
        $this->assertStringContainsString('PHPStan (full analysis)', $output);
    }

    public function test_an_existing_branch_still_diffs_against_the_remote_sha(): void
    {
        // Regression guard for the path that already worked: a real remote sha
        // keeps deciding the range, and an unchanged range still skips.
        [$repo, $base] = $this->makeRepo();

        $this->git($repo, 'update-ref', 'refs/remotes/origin/beta', $base);
        $this->commitFile($repo, 'app/Foo.php');

        [$exit, $output] = $this->runHook($repo, $this->existingBranchStdin($repo, $base));

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($repo.'/phpstan.ran');
        $this->assertStringContainsString('PHPStan (full analysis)', $output);

        unlink($repo.'/phpstan.ran');

        [$exit, $output] = $this->runHook($repo, $this->existingBranchStdin($repo, $this->git($repo, 'rev-parse', 'HEAD')));

        $this->assertSame(0, $exit, $output);
        $this->assertFileDoesNotExist($repo.'/phpstan.ran');
        $this->assertStringContainsString('skipping PHPStan', $output);
    }

    /**
     * A throw-away repo with a base commit and a stubbed vendor/bin/phpstan that
     * only records that it ran.
     *
     * @return array{0: string, 1: string} [repo path, base sha]
     */
    private function makeRepo(): array
    {
        $repo = sys_get_temp_dir().'/pre-push-'.bin2hex(random_bytes(6));
        $this->sandboxes[] = $repo;

        mkdir($repo.'/vendor/bin', 0o777, true);

        file_put_contents(
            $repo.'/vendor/bin/phpstan',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($repo.'/phpstan.ran')."\nexit 0\n"
        );
        chmod($repo.'/vendor/bin/phpstan', 0o755);

        $this->git($repo, 'init', '-q', '-b', 'main');
        $this->git($repo, 'commit', '-q', '--allow-empty', '-m', 'base');

        return [$repo, $this->git($repo, 'rev-parse', 'HEAD')];
    }

    private function commitFile(string $repo, string $path): void
    {
        $full = $repo.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }

        file_put_contents($full, "content\n");
        $this->git($repo, 'add', $path);
        $this->git($repo, 'commit', '-q', '-m', 'add '.$path);
    }

    private function git(string $repo, string ...$args): string
    {
        $command = 'cd '.escapeshellarg($repo)
            .' && git -c user.name=Test -c user.email=test@example.com '
            .implode(' ', array_map(escapeshellarg(...), $args)).' 2>&1';

        $output = [];
        exec($command, $output, $exit);

        $this->assertSame(0, $exit, "git {$args[0]} failed: ".implode("\n", $output));

        return trim(implode("\n", $output));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runHook(string $repo, string $stdin): array
    {
        // git passes no arguments to a pre-push hook; everything arrives on stdin.
        $command = 'cd '.escapeshellarg($repo)
            .' && printf %s '.escapeshellarg($stdin)
            .' | VERIFY_TESTS= bash '.escapeshellarg($this->hookPath('pre-push')).' 2>&1';

        $output = [];
        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    private function newBranchStdin(string $repo, string $branch): string
    {
        return sprintf(
            "refs/heads/%s %s refs/heads/%s %s\n",
            $branch,
            $this->git($repo, 'rev-parse', 'HEAD'),
            $branch,
            str_repeat('0', 40)
        );
    }

    private function existingBranchStdin(string $repo, string $remoteSha): string
    {
        return sprintf(
            "refs/heads/feature %s refs/heads/feature %s\n",
            $this->git($repo, 'rev-parse', 'HEAD'),
            $remoteSha
        );
    }
}
