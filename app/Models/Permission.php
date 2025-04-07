<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['name', 'description'];
    public function accesslevels(): BelongsToMany
    {
        return $this->belongsToMany(AccessLevel::class, 'permission_access_level');
    }
}