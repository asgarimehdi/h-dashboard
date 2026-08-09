<?php

namespace App\Models;

use App\Traits\HasOrganizationalScope;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    use HasOrganizationalScope;
    use PersianNormalizer;

    public function getRouteKeyName(): string
    {
        return 'n_code';
    }

    protected $fillable = ['n_code','f_name','l_name','t_id', 'e_id', 'r_id', 's_id', 'u_id', 'birth_date', 'hire_date', 'status'];
    protected $table = 'persons';

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            $fields = ['f_name', 'l_name'];
            foreach ($fields as $field) {
                if ($model->isDirty($field) && !empty($model->$field) && is_string($model->$field)) {
                    $model->$field = self::normalizeForSearch($model->$field);
                }
            }
        });

        // Invalidate cached HR stats + dashboard whenever a person is created/updated/deleted (#341, #391)
        foreach (['saved', 'deleted'] as $event) {
            static::$event(function () {
                \Illuminate\Support\Facades\Cache::increment('hr_stats_version');
                \Illuminate\Support\Facades\Cache::increment('dashboard_version');
                \Illuminate\Support\Facades\Cache::increment('maps_version');
            });
        }
    }

    /**
     * دریافت اطلاعات User مرتبط با این Person.
     * چون کلید خارجی (n_code) در جدول users است، Person یک User دارد.
     */
    public function user(): HasOne // <--- تغییر به HasOne
    {
        // پارامتر دوم: نام کلید خارجی در جدول users
        // پارامتر سوم: نام کلید محلی در جدول persons (این جدول)
        return $this->hasOne(User::class, 'n_code', 'n_code'); // <--- تغییر به hasOne
    }

    // ... بقیه روابط BelongsTo برای estekhdam, radif, etc. صحیح هستند ...
    public function estekhdam(): BelongsTo
    {
        return $this->belongsTo(Estekhdam::class, 'e_id');
    }
    public function radif(): BelongsTo
    {
        return $this->belongsTo(Radif::class, 'r_id');
    }
    public function semat(): BelongsTo
    {
        return $this->belongsTo(Semat::class, 's_id');
    }
    public function tahsil(): BelongsTo
    {
        return $this->belongsTo(Tahsil::class, 't_id');
    }
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'u_id');
    }
}
