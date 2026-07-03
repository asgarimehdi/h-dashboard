<?php

namespace App\Models;

use App\Traits\HasOrganizationalScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    use HasOrganizationalScope;
    protected $fillable = [
        'title',
        'start_at',
        'end_at',
        'is_completed',
        'unit_id',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    // تیکت‌های مرتبط با این وظیفه
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'task_id');
    }
}
