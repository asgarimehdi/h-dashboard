<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'description'];
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }
    public function accesslevels(): BelongsToMany
    {
        return $this->belongsToMany(AccessLevel::class, 'role_access_level');
    }
  
}