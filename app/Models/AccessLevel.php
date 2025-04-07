<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use Illuminate\Database\Eloquent\Model;

class AccessLevel extends Model
{
    protected $fillable = ['name', 'description'];
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_access_level');
    }
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_access_level');
    }
    //
}