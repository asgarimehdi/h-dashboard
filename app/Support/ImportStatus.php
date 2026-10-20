<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The completion channel between a queued import and the browser (#1194).
 *
 * The confirmed import runs in a worker: by the time the rows exist, the
 * Livewire request that queued the job is long gone, so the component cannot
 * dispatch `hardware-imported` at dispatch time — doing so refreshed the
 * parent table before a single row existed, and the imported devices stayed
 * invisible until a manual reload. There is no websocket in this app, so the
 * job publishes its result here and the component polls for it.
 *
 * The key is scoped by user AND by a per-dispatch token: two imports by the
 * same user (or two users) must not read each other's result, and a stale
 * entry from an earlier import must not be mistaken for this one.
 */
final class ImportStatus
{
    /**
     * How long a published result stays readable.
     */
    private const TTL_SECONDS = 3600;

    /**
     * How long the "an import is running" claim survives without the worker
     * reporting back. A worker that is killed mid-run leaves no result at
     * all; the claim expires so the user is not locked out forever.
     */
    private const CLAIM_TTL_SECONDS = 900;

    /**
     * The per-dispatch token that identifies one queued import.
     */
    public static function token(int $userId): string
    {
        return $userId.'-'.Str::uuid();
    }

    /**
     * @return string The cache key for one dispatch.
     */
    public static function key(int $userId, string $token): string
    {
        return 'import-status:'.$userId.':'.$token;
    }

    /**
     * @return string The cache key holding the "import running" claim.
     */
    public static function claimKey(int $userId): string
    {
        return 'import-running:'.$userId;
    }

    /**
     * Claim the single import slot of a user.
     *
     * `Cache::add` is atomic, so two clicks landing at the same time cannot
     * both win: exactly one gets the claim. The loser is told that an import
     * is already running instead of silently queueing a second job that
     * would compare against the pre-import state and duplicate rows.
     *
     * @return bool True when the claim was won.
     */
    public static function claim(int $userId, string $token): bool
    {
        return (bool) Cache::add(self::claimKey($userId), $token, self::CLAIM_TTL_SECONDS);
    }

    /**
     * Release the import slot — called by the worker when the import ended,
     * successfully or not.
     */
    public static function release(int $userId): void
    {
        Cache::forget(self::claimKey($userId));
    }

    /**
     * How many per-row error messages are carried to the browser. A sheet can
     * produce thousands of errors; the toast only has room for a few, and the
     * full list stays in the job's log.
     */
    private const MAX_REPORTED_ERRORS = 5;

    /**
     * Publish the outcome of a finished import.
     *
     * @param  array<string, mixed>  $results
     */
    public static function publish(int $userId, string $token, array $results): void
    {
        $errors = $results['errors'] ?? [];

        Cache::put(
            self::key($userId, $token),
            [
                'state' => 'done',
                'created' => (int) ($results['created'] ?? 0),
                'updated' => (int) ($results['updated'] ?? 0),
                'skipped' => (int) ($results['skipped'] ?? 0),
                'errors' => count($errors),
                'error_messages' => self::errorMessages($errors),
            ],
            self::TTL_SECONDS
        );
    }

    /**
     * The per-row error messages, capped and flattened to plain strings.
     *
     * @param  array<int, mixed>  $errors
     * @return array<int, string>
     */
    private static function errorMessages(array $errors): array
    {
        $messages = [];

        foreach (array_slice($errors, 0, self::MAX_REPORTED_ERRORS) as $error) {
            if (is_array($error)) {
                $row = $error['row'] ?? null;
                $message = $error['error'] ?? null;

                if (is_string($message) && $message !== '') {
                    $messages[] = $row === null ? $message : "ردیف {$row}: {$message}";
                }

                continue;
            }

            if (is_string($error) && $error !== '') {
                $messages[] = $error;
            }
        }

        return $messages;
    }

    /**
     * Publish a failure so the user is not left watching a spinner forever.
     */
    public static function publishFailure(int $userId, string $token, string $message): void
    {
        Cache::put(
            self::key($userId, $token),
            [
                'state' => 'failed',
                'error' => $message,
            ],
            self::TTL_SECONDS
        );
    }

    /**
     * Read a published result, or null while the worker has not finished.
     *
     * @return array<string, mixed>|null
     */
    public static function read(int $userId, string $token): ?array
    {
        $value = Cache::get(self::key($userId, $token));

        return is_array($value) ? $value : null;
    }

    /**
     * Drop a published result once the browser has consumed it.
     */
    public static function forget(int $userId, string $token): void
    {
        Cache::forget(self::key($userId, $token));
    }
}
