<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

use Illuminate\Database\Eloquent\Relations\HasOne;
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [

        'n_code',
        'password',
    ];
    public function person(): hasOne
    {
        return $this->hasOne(Person::class, 'n_code', 'n_code');
    }
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->person->f_name . ' ' . $this->person->l_name,
        );
    }
    // Accessor برای گرفتن نام واحد
    public function getUnitNameAttribute()
    {
        return $this->person->unit->name ?? '-';
    }
    // Accessor برای نقش‌ها
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
        return $this->roles()->whereHas('permissions', function ($query) use ($permission) {
            $query->where('name', $permission);
        })->exists();
    }
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}