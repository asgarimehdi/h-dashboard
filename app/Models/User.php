<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Casts\Attribute;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;


class User extends Authenticatable
{
    use HasApiTokens,HasFactory, Notifiable,SoftDeletes;
    // The User model requires this trait
    use HasRoles;
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
        return Attribute::make(
            get: function () {
                // کلید یکتا برای هر کاربر
                $sessionKey = "user_{$this->id}_display_name";

                // اول از session بخوان
                if (($cached = session($sessionKey)) !== null) {
//                    \Log::info("[SESSION] Hit for user {$this->id}");
                    return $cached;
                }

//                \Log::info("[DB] Loading person for user {$this->id}");

                // دیتابیس — فقط اولین بار بعد از لاگین
                $person = $this->relationLoaded('person')
                    ? $this->person
                    : $this->person()->first();

                $name = $person
                    ? ($person->f_name . ' ' . $person->l_name)
                    : 'کاربر بدون پروفایل';

                // ذخیره در session — تا لاگ‌اوت
                session([$sessionKey => $name]);

                return $name;
            }
        );
    }

    public function getUnitNameAttribute()
    {
        return $this->person?->unit?->name ?? '-'; // استفاده از nullsafe operator
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
