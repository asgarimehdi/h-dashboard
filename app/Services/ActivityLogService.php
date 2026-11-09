<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogService
{
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
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    public static function created(object $subject, string $description = ''): ActivityLog
    {
        $name = method_exists($subject, 'getTitle') ? $subject->getTitle() : class_basename($subject);

        return self::log('created', $subject, $description ?: "ایجاد {$name}");
    }

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
