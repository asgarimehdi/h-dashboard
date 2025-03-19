<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    protected $table = 'persons';
    public function user(): belongsTo
    {
        return $this->belongsTo(User::class, 'n_code', 'n_code');
    }
}