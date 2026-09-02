<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskActivity extends Model
{
    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'description',
        'is_internal',
        'to_unit_id',
        'to_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'activity_id');
    }
}
