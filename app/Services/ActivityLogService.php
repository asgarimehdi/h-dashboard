<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogService
{
    /**
     * Keys that must never reach `old_values` / `new_values` (issue #1400).
     *
     * Credentials/tokens, direct identifiers, and the two dates that
     * re-identify a person. Names are deliberately absent: they are the
     * audit's useful content and the row already carries subject_type/id.
     * The list is a policy, not a mirror of one model's columns.
     *
     * @var list<string>
     */
    private const DENIED_KEYS = [
        // credentials / tokens
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        // direct identifiers
        'n_code',
        'kod_melli',
        'phone',
        'email',
        // dates that re-identify a person
        'birth_date',
        'hire_date',
    ];

    /**
     * Write one audit row.
     *
     * `$oldValues` / `$newValues` must be an EXPLICIT, allowlisted delta —
     * the keys you intend to audit, with scalar or nested-array values.
     * Never pass a raw model array (`getAttributes()`, `getChanges()`,
     * `toArray()`): it drags every column into the trail. Credential and
     * identifier keys are stripped in write() (issue #1400) as a backstop,
     * not as a licence to pass the raw array.
     */
    public static function log(
        string $type,
        ?object $subject = null,
        string $description = '',
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): ActivityLog {
        return self::write(self::actorId(), $type, $subject, $description, $oldValues, $newValues, $request);
    }

    /**
     * Log an action with a KNOWN actor (issue #1375).
     *
     * `log()` always takes the acting user of the request. A restore can
     * REPLACE that very user — the `users` table is part of the backup — and
     * then the acting id points at an account that no longer exists. An
     * insert that carried it would violate the foreign key of
     * `activity_logs.user_id`, so the caller passes `null` and the log is
     * written without an actor.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function logAs(
        ?int $userId,
        string $type,
        ?object $subject = null,
        string $description = '',
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): ActivityLog {
        return self::write($userId, $type, $subject, $description, $oldValues, $newValues, $request);
    }

    /**
     * The acting user of the request, as an id.
     */
    private static function actorId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Write one activity log row.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private static function write(
        ?int $userId,
        string $type,
        ?object $subject = null,
        string $description = '',
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): ActivityLog {
        $request = $request ?? request();

        return ActivityLog::create([
            'user_id' => $userId,
            'type' => $type,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'description' => $description,
            'old_values' => self::sanitizeValues($oldValues),
            'new_values' => self::sanitizeValues($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Strip denied keys from one value set, at every depth.
     *
     * Arrays only — a caller that nests a model instance inside the payload
     * is outside the documented contract (see log()/updated()).
     *
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    private static function sanitizeValues(?array $values): ?array
    {
        return $values === null ? null : self::stripDenied($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function stripDenied(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::DENIED_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::stripDenied($value) : $value;
        }

        return $clean;
    }

    public static function created(object $subject, string $description = ''): ActivityLog
    {
        $name = method_exists($subject, 'getTitle') ? $subject->getTitle() : class_basename($subject);

        return self::log('created', $subject, $description ?: "ایجاد {$name}");
    }

    /**
     * `$oldValues` / `$newValues` follow the log() contract: an explicit
     * allowlisted delta, never a raw model array (issue #1400).
     */
    public static function updated(object $subject, array $oldValues, array $newValues, string $description = ''): ActivityLog
    {
        $name = method_exists($subject, 'getTitle') ? $subject->getTitle() : class_basename($subject);

        return self::log('updated', $subject, $description ?: "ویرایش {$name}", $oldValues, $newValues);
    }

    public static function deleted(object $subject, string $description = ''): ActivityLog
    {
        $name = method_exists($subject, 'getTitle') ? $subject->getTitle() : class_basename($subject);

        return self::log('deleted', $subject, $description ?: "حذف {$name}");
    }

    public static function login(string $description = 'ورود به سیستم'): ActivityLog
    {
        return self::log('login', null, $description);
    }

    public static function logout(string $description = 'خروج از سیستم'): ActivityLog
    {
        return self::log('logout', null, $description);
    }
}
