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
        $request = $request ?? request();

        return ActivityLog::create([
            'user_id' => auth()->id(),
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
