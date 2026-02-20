<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Morilog\Jalali\Jalalian;

class Ticket extends Model
{

    protected $fillable = [
        'ticket_code',
        'user_id',
        'unit_id',
        'subject',
        'content',
        'priority',
        'status',
        'task_id',
        'current_assignee_id',
        'accepted_at',
        'completed_at',
    ];
    public function canBeCompleted()
    {
        return $this->status === 'accepted';
    }
    public function getWaitingDurationAttribute()
    {
        $totalHours = floor($this->created_at->diffInHours(now()));

        if ($totalHours < 1) {
            return ['text' => 'کمتر از ۱ ساعت', 'class' => 'bg-emerald-100 text-emerald-700'];
        }

        if ($totalHours < 24) {
            return ['text' => $totalHours . ' ساعت', 'class' => 'bg-emerald-100 text-emerald-700'];
        } elseif ($totalHours < 48) {
            return ['text' => '۱ روز و ' . ($totalHours - 24) . ' ساعت', 'class' => 'bg-orange-100 text-orange-700'];
        } else {
            $days = floor($totalHours / 24);
            return ['text' => $days . ' روز و ' . ($totalHours % 24) . ' ساعت', 'class' => 'bg-red-100 text-red-700 animate-pulse'];
        }
    }
    // تعریف status_name برای نمایش فارسی وضعیت‌های تیکت
    public function getStatusNameAttribute()
    {
        return match ($this->status) {
            'created' => 'جدید (واحد)',
            'forwarded' => 'ارجاع شده',
            'accepted' => 'در حال پیگیری',
            'completed' => 'پایان یافته',
            'rejected' => 'رد شده',
            default => 'نامشخص',
        };
    }
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // رابطه با شخصی که تیکت به او واگذار شده
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_assignee_id');
    }
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // رابطه با فعالیت‌ها
    public function activities()
    {
        // دقت کنید که در مایگریشن جدید نام فیلد را ticket_id گذاشتیم
        return $this->hasMany(TaskActivity::class, 'ticket_id');
    }
}
