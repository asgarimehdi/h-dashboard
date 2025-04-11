<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes; // اضافه کردن SoftDeletes

// --->>> اضافه شد
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// --->>> حذف شد: HasOne دیگر اینجا استفاده نمی‌شود
// use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasFactory, Notifiable,SoftDeletes;

    protected $fillable = [
        'n_code',
        'password',
    ];
    protected $dates = ['deleted_at']; // برای مدیریت تاریخ حذف
    /**
     * دریافت اطلاعات Person مرتبط با این User.
     * چون کلید خارجی (n_code) در جدول users است، از belongsTo استفاده می‌کنیم.
     */
    public function person(): BelongsTo // <--- تغییر به BelongsTo
    {
        // پارامتر دوم: نام کلید خارجی در جدول users (این جدول)
        // پارامتر سوم: نام کلید مالک (کلید اصلی یا unique) در جدول persons
        return $this->belongsTo(Person::class, 'n_code', 'n_code'); // <--- تغییر به belongsTo
    }

    // Accessor ها به درستی از $this->person استفاده می‌کنند و نیازی به تغییر ندارند
    protected function name(): Attribute
    {
        // اطمینان از وجود person قبل از دسترسی به پراپرتی‌ها
        return Attribute::make(
            get: fn() => $this->person ? ($this->person->f_name . ' ' . $this->person->l_name) : 'کاربر بدون پروفایل',
        );
    }

    public function getUnitNameAttribute()
    {
        return $this->person?->unit?->name ?? '-'; // استفاده از nullsafe operator
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