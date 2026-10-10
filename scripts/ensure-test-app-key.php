#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Issue #953 — give a fresh clone a `.env.testing` key, and never rotate one.
 *
 * `.env.testing` is git-tracked, so blanking its `APP_KEY` (#953) also removes
 * the key a local `composer test` needs. `LoadEnvironmentVariables` swaps the
 * env file to `.env.testing` whenever `APP_ENV=testing` and dotenv's repository
 * is immutable, so a blank key there wins over a perfectly good `.env` key and
 * every test that resolves the encrypter dies with
 * `MissingAppKeyException`. CI never notices: each of its jobs runs
 * `key:generate --env=testing` before the suite, which is precisely why the
 * committed key sat unnoticed for eight months.
 *
 * So the key comes back — but only when there is none, and only in the working
 * tree:
 *
 *   - **Conditional.** An unconditional `key:generate` on every run rotates the
 *     tracked file each time, leaving it permanently dirty and a fresh real key
 *     one `git add` away from a commit. That is the same defect this issue
 *     removes, recreated by the fix.
 *   - **Working tree only.** The generated key is never staged, and
 *     `scripts/check-app-key-leak.php` reads the git INDEX, so a clone running
 *     the suite all day still reports no leak.
 *
 * Usage:  php scripts/ensure-test-app-key.php [path]
 * Exit:   0 = `.env.testing` exists and now has a key (or already had one),
 *         2 = no `.env.testing` — a misconfigured clone, not something to fix
 *             here by inventing a file that also carries DB settings.
 */
$root = $argv[1] ?? dirname(__DIR__);
$file = $root.'/.env.testing';

if (! is_file($file) || ! is_readable($file)) {
    fwrite(STDERR, "No .env.testing in {$root}.\n");
    fwrite(STDERR, "Copy .env.example to .env.testing and set its DB_* values, then try again.\n");

    exit(2);
}

$contents = file_get_contents($file);

/**
 * The value of the first `APP_KEY` assignment, or null when the file has none.
 *
 * Mirrors the emptiness rule in `scripts/check-app-key-leak.php` on purpose:
 * two scripts deciding "is this key empty?" is exactly the pair that drifts,
 * and a disagreement here means the bootstrap skips a key the suite then
 * cannot boot without. `APP_KEY=""`, `APP_KEY=''` and `APP_KEY=   ` all parse
 * to an empty value for Laravel, so all three count as empty.
 */
$currentKey = static function (string $contents): ?string {
    if (! preg_match_all('/^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=[ \t]*(.*)$/m', $contents, $matches)) {
        return null;
    }

    foreach ($matches[1] as $raw) {
        $value = trim((string) preg_replace('/[ \t]+#.*$/', '', trim($raw)));

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

$existing = $currentKey($contents);

if ($existing !== null && $existing !== '') {
    // Never print it, never rewrite it.
    echo "APP_KEY is already set in .env.testing — leaving it alone.\n";

    exit(0);
}

/**
 * Laravel's own generator, so the format is never a second opinion.
 *
 * `Encrypter::generateKey()` is what `key:generate` calls; `config('app.cipher')`
 * cannot be read here because booting the app is exactly what the blank key
 * prevents, so the default cipher's 32 bytes are written literally.
 */
$key = 'base64:'.base64_encode(random_bytes(32));

$rewritten = preg_replace(
    '/^APP_KEY[ \t]*=[ \t]*.*$/m',
    'APP_KEY='.$key,
    $contents,
    1
);

if ($rewritten === null || $rewritten === $contents) {
    fwrite(STDERR, "Could not find an APP_KEY line to rewrite in .env.testing.\n");
    fwrite(STDERR, "Add a blank `APP_KEY=` line to the file (issue #953).\n");

    exit(2);
}

if (file_put_contents($file, $rewritten) === false) {
    fwrite(STDERR, "Could not write .env.testing in {$root}.\n");

    exit(2);
}

// The key value itself is deliberately absent from this line: CI and test
// output are logged, and `key:generate` prints a comment-wrapped key by design
// — this script exists so nobody has to read one.
echo "Generated a per-clone APP_KEY in .env.testing (value not shown).\n";

exit(0);
