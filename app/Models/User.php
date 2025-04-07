<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['n_code', 'password'];

    public function person(): HasOne
    {
        return $this->hasOne(Person::class, 'n_code', 'n_code');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->person->f_name . ' ' . $this->person->l_name,
        );
    }

    public function getUnitNameAttribute()
    {
        return $this->person->unit->name ?? '-';
    }

    public function getRolesNameAttribute()
    {
        return $this->roles->pluck('name')->implode(', ') ?: '-';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole($role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function hasPermission($permission): bool
    {
        $selectedRoleId = session('selected_role');
        if (!$selectedRoleId) {
            return false; // اگر نقش انتخاب نشده باشه، دسترسی نداره
        }

        return $this->roles()
            ->where('roles.id', $selectedRoleId)
            ->whereHas('accesslevels.permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })->exists();
    }

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}