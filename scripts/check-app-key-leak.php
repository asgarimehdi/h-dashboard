#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Issue #953 — fail if a git-tracked environment file carries a real APP_KEY.
 *
 * Four tracked env files (`.env.example.mysql`, `.env.example.pgsql`,
 * `.env.testing`, `.env-example-github`) each held the SAME live APP_KEY,
 * byte-identical to a developer's working `.env`, first committed in
 * 2026-02-07. `APP_KEY` is not a placeholder credential — it is the master
 * secret: `config/session.php` encrypts session payloads with it and signs the
 * cookie MAC, so a holder can mint a session cookie for any account.
 *
 * Blanking the four files prevents recurrence, but nothing stopped it coming
 * back: CI runs `key:generate --env=testing` on every job, so the pipeline
 * never needed a real key in those files and never noticed one was there. This
 * is that missing gate.
 *
 * Two rules keep it usable rather than merely loud:
 *
 *   1. **Tracked files only.** A developer's working `.env` is gitignored and
 *      MUST hold a real key — that is what the app runs on. Reporting it would
 *      make the guard fail on a correct machine and bury the tracked-file case.
 *   2. **Never print the value.** The report names the file and the line, never
 *      the key, so a failing CI run does not republish the secret it found.
 *
 * The scan reads the git INDEX, not the working tree. Two reasons, both of
 * which cost real work if they are ignored:
 *
 *   - `.env.testing` is BOTH tracked and the file a local `composer test`
 *     generates a per-clone key into. Reading the working tree would fail this
 *     guard on a correct machine after the first local test run, and pressure
 *     the next developer into committing a generated key — the exact defect
 *     this issue removes. An unstaged key is not committed.
 *   - Deleting the key from a tracked file is itself the fix. With the key
 *     staged for deletion the scan must stay green, because the next commit
 *     removes it.
 *
 * Usage:  php scripts/check-app-key-leak.php [path]
 * Exit:   0 = no tracked env file carries a key,
 *         1 = at least one does,
 *         2 = the scan itself could not run.
 */
$root = $argv[1] ?? dirname(__DIR__);

if (! is_dir($root)) {
    fwrite(STDERR, "Cannot scan — no such directory: {$root}\n");

    exit(2);
}

$root = realpath($root) ?: $root;

/**
 * Run git inside the tree and return stdout, or null when git is unusable.
 *
 * @return array{0: list<string>, 1: int}|null
 */
$git = static function (string ...$args) use ($root): ?array {
    $command = 'git -C '.escapeshellarg($root).' '.implode(' ', $args).' 2>/dev/null';

    exec($command, $output, $exit);

    // `ls-files -z` emits NUL-separated records, which exec() hands back as one
    // element per record when the output has no trailing newline semantics we
    // can rely on — splitting here keeps a filename containing a space intact.
    $records = [];
    foreach ($output as $line) {
        $records = array_merge($records, array_filter(explode("\0", $line), static fn ($r) => $r !== ''));
    }

    return [$records, $exit];
};

$insideWorkTree = $git('rev-parse', '--is-inside-work-tree');

if ($insideWorkTree === null || $insideWorkTree[1] !== 0 || ($insideWorkTree[0][0] ?? '') !== 'true') {
    fwrite(STDERR, "Cannot scan — {$root} is not inside a git work tree.\n");
    fwrite(STDERR, "This guard reads the git index, so there is nothing to scan without git.\n");

    exit(2);
}

$listed = $git('ls-files', '-z');

if ($listed === null || $listed[1] !== 0) {
    fwrite(STDERR, "Cannot scan — `git ls-files` failed in {$root}.\n");

    exit(2);
}

/**
 * Is this path an environment file? Only these can leak a key.
 *
 * Matches the shipped set — `.env`, `.env.testing`, `.env.example.pgsql`,
 * `.env-example-github` — by shape rather than by a hardcoded list, so a new
 * template is covered the moment it is added. `phpunit.xml` and the docs
 * mention APP_KEY legitimately and are not environment files.
 */
$isEnvironmentFile = static function (string $path): bool {
    return (bool) preg_match('#(^|/)\.?env([.-].*)?$#', $path);
};

/**
 * The value of the first APP_KEY assignment in a dotenv file, or null when
 * there is none.
 *
 * Handles what a dotenv file can actually contain: `export APP_KEY=…`,
 * surrounding whitespace, and single/double quotes. A commented-out
 * `# APP_KEY=…` is not an assignment and never counts.
 */
$appKeyValue = static function (string $contents): ?string {
    if (! preg_match_all('/^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=[ \t]*(.*)$/m', $contents, $matches)) {
        return null;
    }

    foreach ($matches[1] as $raw) {
        $value = trim($raw);

        // An inline comment only ends the value when it is separated by space,
        // so a key containing `#` is not truncated.
        $value = trim((string) preg_replace('/[ \t]+#.*$/', '', $value));

        if (strlen($value) >= 2) {
            $first = $value[0];

            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value);
    }

    return null;
};

$offenders = [];
$scanned = 0;

foreach ($listed[0] as $path) {
    if (! $isEnvironmentFile($path)) {
        continue;
    }

    // `:path` reads the staged content, so a key that is only in the working
    // tree is not reported. A path staged for deletion has no `:path` entry
    // any more, which is correct: the next commit drops the file.
    $staged = $git('show', ':'.$path);

    if ($staged === null) {
        fwrite(STDERR, "Cannot read staged content of {$path}\n");

        exit(2);
    }

    if ($staged[1] !== 0) {
        fwrite(STDERR, "Cannot read staged content of {$path}\n");

        exit(2);
    }

    $scanned++;

    $contents = implode("\n", $staged[0]);

    if (($value = $appKeyValue($contents)) !== null && $value !== '') {
        $offenders[] = $path;
    }
}

echo "Scanned {$scanned} tracked environment file(s) in {$root}.\n";

if ($offenders === []) {
    echo "OK — no tracked env file carries an APP_KEY.\n";

    exit(0);
}

echo "\n";
echo count($offenders)." tracked environment file(s) carry a real APP_KEY:\n";

foreach ($offenders as $path) {
    echo "  - {$path}\n";
}

echo "\n";
echo "APP_KEY is the master secret: it encrypts session payloads and signs the\n";
echo "cookie MAC, so anyone holding it can mint a cookie for any account. Do not\n";
echo "echo its value — blank it (APP_KEY=) and let `php artisan key:generate`\n";
echo "produce a per-clone key. An empty key fails loudly at boot; a committed\n";
echo "one fails silently (issue #953).\n";

exit(1);
