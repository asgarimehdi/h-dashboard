<?php

namespace App\Support\Backup;

/**
 * The version stamped into a backup manifest (owner decision Q3-(a)).
 *
 * The manifest records the commit SHA of `beta` — the line the backup was
 * taken against — so a restore can later be compared with the code that
 * produced the file.
 *
 * The SHA is read from the repository metadata instead of a shell call:
 * `git` is not guaranteed to exist in a production image, and a subprocess
 * per export would be a needless dependency. The `beta` ref may live in
 * `.git/refs/heads/beta`, in `.git/packed-refs`, or — in a linked worktree —
 * in the common git directory. When the ref cannot be found at all, the
 * running HEAD is used, and a deployment without any repository metadata
 * reports `unknown`.
 */
final class ApplicationVersion
{
    /**
     * The commit SHA of the `beta` branch, or a fallback identifier.
     */
    public static function resolve(): string
    {
        $gitDir = self::gitDir();

        if ($gitDir === null) {
            return 'unknown';
        }

        $commonDir = self::commonDir($gitDir);

        foreach (['refs/heads/beta', 'refs/remotes/origin/beta', 'refs/remotes/upstream/beta'] as $ref) {
            $sha = self::readRef($commonDir, $ref);

            if ($sha !== null) {
                return $sha;
            }
        }

        return self::readHead($gitDir, $commonDir) ?? 'unknown';
    }

    /**
     * The git directory of this checkout — a directory in a normal clone, a
     * `gitdir:` pointer file in a linked worktree.
     */
    private static function gitDir(): ?string
    {
        $path = base_path('.git');

        if (is_dir($path)) {
            return $path;
        }

        if (! is_file($path)) {
            return null;
        }

        $content = trim((string) file_get_contents($path));

        if (! str_starts_with($content, 'gitdir:')) {
            return null;
        }

        $directory = trim(substr($content, 7));

        if ($directory === '') {
            return null;
        }

        if (! str_starts_with($directory, '/')) {
            $directory = base_path($directory);
        }

        return is_dir($directory) ? $directory : null;
    }

    /**
     * The directory holding the shared refs — the same one for every linked
     * worktree of a repository.
     */
    private static function commonDir(string $gitDir): string
    {
        $pointer = $gitDir.'/commondir';

        if (! is_file($pointer)) {
            return $gitDir;
        }

        $relative = trim((string) file_get_contents($pointer));

        if ($relative === '') {
            return $gitDir;
        }

        $resolved = realpath($gitDir.'/'.$relative);

        return $resolved !== false ? $resolved : $gitDir;
    }

    /**
     * Resolve a ref (loose first, then packed) to its commit SHA.
     */
    private static function readRef(string $commonDir, string $ref): ?string
    {
        $loose = $commonDir.'/'.$ref;

        if (is_file($loose)) {
            $value = trim((string) file_get_contents($loose));

            if (self::isSha($value)) {
                return $value;
            }
        }

        $packed = $commonDir.'/packed-refs';

        if (! is_file($packed)) {
            return null;
        }

        $lines = file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line), 2);

            if ($parts === false || count($parts) !== 2) {
                continue;
            }

            if ($parts[1] === $ref && self::isSha($parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * The commit SHA HEAD points at.
     */
    private static function readHead(string $gitDir, string $commonDir): ?string
    {
        $head = $gitDir.'/HEAD';

        if (! is_file($head)) {
            return null;
        }

        $value = trim((string) file_get_contents($head));

        if (self::isSha($value)) {
            return $value;
        }

        if (str_starts_with($value, 'ref: ')) {
            return self::readRef($commonDir, trim(substr($value, 5)));
        }

        return null;
    }

    private static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }
}
