#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Issues #953 / #1035 — fail if a git-tracked environment file carries a real
 * credential.
 *
 * Issue #953 was the APP_KEY: four tracked env files (`.env.example.mysql`,
 * `.env.example.pgsql`, `.env.testing`, `.env-example-github`) each held the
 * SAME live APP_KEY, byte-identical to a developer's working `.env`, first
 * committed in 2026-02-07. `APP_KEY` is not a placeholder credential — it is
 * the master secret: `config/session.php` encrypts session payloads with it and
 * signs the cookie MAC, so a holder can mint a session cookie for any account.
 *
 * Issue #1035 is the same defect one tier down: literal DB/Redis passwords in
 * `.env.example.pgsql`, `.env.testing` and `.env-example-github`, and a literal
 * Zabbix token in `.env.example`. This guard covers those too.
 *
 * Two rules keep it usable rather than merely loud:
 *
 *   1. **Tracked files only.** A developer's working `.env` is gitignored and
 *      MUST hold a real key — that is what the app runs on. Reporting it would
 *      make the guard fail on a correct machine and bury the tracked-file case.
 *   2. **Never print the value.** The report names the file and the key, never
 *      the value, so a failing CI run does not republish the secret it found.
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
 * Why the second pass is not simply "any non-empty value" (#1035's correction
 * to the original issue text): the templates LEGITIMATELY ship a duration
 * (`SANCTUM_TOKEN_EXPIRATION`), a throwaway numeric password, and the
 * `TEST_*` fixtures the Playwright specs read through `requireEnv()`. A guard
 * that fails a correct tree is a guard somebody disables, so a secret-shaped
 * key is acceptable only when its value is one of three layers — empty, a
 * `<placeholder>`, or a `${{ secrets.NAME }}` expression — and anything else is
 * a literal. Names shaped like a test fixture (`TEST_*`) are out of scope, and
 * the key's SUFFIX decides whether it is policed at all, so
 * `SANCTUM_TOKEN_EXPIRATION` (a duration) is not mistaken for a token.
 *
 * `APP_KEY` keeps #953's stricter rule — it must be EMPTY — on purpose. A
 * `<placeholder>` is not a usable key, and `scripts/ensure-test-app-key.php`
 * will not generate one over it, so accepting a placeholder here would let a
 * clone commit a tree whose suite cannot boot. `EnvSecretGuardTest` pins that
 * the two scripts agree on what counts as empty.
 *
 * Usage:  php scripts/check-app-key-leak.php [path]
 * Exit:   0 = no tracked env file carries a literal credential,
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
 * Normalise a raw dotenv assignment value: trim, drop a space-separated inline
 * comment (so a value containing `#` is not truncated), and strip one layer of
 * matching quotes. `APP_KEY=""`, `APP_KEY=''` and `APP_KEY=   ` all parse to an
 * empty value for Laravel, so all three normalise to `''`.
 */
$normaliseValue = static function (string $raw): string {
    $value = trim((string) preg_replace('/[ \t]+#.*$/', '', trim($raw)));

    if (strlen($value) >= 2) {
        $first = $value[0];

        if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
            $value = substr($value, 1, -1);
        }
    }

    return trim($value);
};

/**
 * Every assignment in a dotenv file as `name => normalised value`, first
 * assignment winning.
 *
 * First-wins is not arbitrary: laravel's dotenv repository is immutable, so a
 * repeated key is set by its first assignment and ignored afterwards — reading
 * the last one would police a line the app never sees.
 *
 * Handles what a dotenv file can actually contain: `export APP_KEY=…`,
 * surrounding whitespace, and single/double quotes. A commented-out
 * `# APP_KEY=…` is not an assignment and never counts.
 *
 * @return array<string, string>
 */
$assignments = static function (string $contents) use ($normaliseValue): array {
    if (! preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*(.*)$/m', $contents, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $found = [];

    foreach ($matches as $match) {
        if (array_key_exists($match[1], $found)) {
            continue;
        }

        $found[$match[1]] = $normaliseValue($match[2]);
    }

    return $found;
};

/**
 * The shared three-layer policy (issue #1035): a credential in a tracked env
 * file is acceptable when it is empty, a `<placeholder>`, or a
 * `${{ secrets.NAME }}` reference. Anything else is a literal and must not be
 * committed.
 *
 * Every guard that asks "is this value safe to commit?" must read this one
 * definition — a second, independently written copy is exactly the pair that
 * drifts apart.
 */
$acceptableSecretValue = static function (string $value): bool {
    if ($value === '') {
        return true;
    }

    if (preg_match('/^<[^>]*>$/', $value) === 1) {
        return true;
    }

    return preg_match('/^\$\{\{\s*secrets\.[A-Za-z0-9_.-]+\s*\}\}$/', $value) === 1;
};

/**
 * Does this variable name hold a credential?
 *
 * The SUFFIX decides, not a substring: `SANCTUM_TOKEN_EXPIRATION` is a
 * duration and `REDIS_PORT` is a port, while `DB_PASSWORD`, `REDIS_PASSWORD`,
 * `ZABBIX_TOKEN`, `APP_KEY` and `SUPABASE_*_KEY` are credentials. A
 * `PASSWORD`/`SECRET`/`TOKEN`/`KEY` substring match would fail the duration and
 * force the guard to be disabled.
 */
$isSecretKey = static function (string $name): bool {
    return preg_match('/(?:^|_)(?:PASSWORD|SECRET|TOKEN|KEY)$/i', $name) === 1;
};

/**
 * Test fixtures are out of scope: `.env.e2e.example` ships `TEST_PASSWORD`,
 * which the Playwright helpers read through `requireEnv()`. Blanking it breaks
 * e2e, and a fixture value is not a production credential.
 */
$isTestFixtureKey = static function (string $name): bool {
    return preg_match('/^TEST_/i', $name) === 1;
};

/** @var list<array{0: string, 1: string}> $offenders */
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
    $values = $assignments($contents);

    // #953. APP_KEY is stricter than the three-layer rule: a placeholder key is
    // not a usable key, and the bootstrap will not replace one.
    if (($value = $values['APP_KEY'] ?? null) !== null && $value !== '') {
        $offenders[] = [$path, 'APP_KEY'];
    }

    // #1035. Every other credential-shaped key must clear the three-layer rule.
    foreach ($values as $name => $value) {
        if ($name === 'APP_KEY' || ! $isSecretKey($name) || $isTestFixtureKey($name)) {
            continue;
        }

        if (! $acceptableSecretValue($value)) {
            $offenders[] = [$path, $name];
        }
    }
}

echo "Scanned {$scanned} tracked environment file(s) in {$root}.\n";

if ($offenders === []) {
    echo "OK — no tracked env file carries a literal credential.\n";

    exit(0);
}

echo "\n";
echo count($offenders)." literal credential(s) in tracked environment file(s):\n";

foreach ($offenders as [$path, $name]) {
    echo "  - {$path}: {$name}\n";
}

echo "\n";
echo "Nothing here is a placeholder or a secret-store reference, so a clone can\n";
echo "read it. Blank it (APP_KEY=, or KEY=) and let `php artisan key:generate`\n";
echo "produce a per-clone APP_KEY, or replace the literal with a `<placeholder>`\n";
echo "or a `\${{ secrets.NAME }}` expression. An empty APP_KEY fails loudly at\n";
echo "boot; a committed one fails silently (issues #953, #1035).\n";

exit(1);
