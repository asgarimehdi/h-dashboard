<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use App\Traits\HasOrganizationalScope;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    use HasFactory;
    use HasOrganizationalScope;
    use PersianNormalizer;

    public function getRouteKeyName(): string
    {
        return 'n_code';
    }

    protected $fillable = ['n_code', 'f_name', 'l_name', 't_id', 'e_id', 'r_id', 's_id', 'u_id', 'birth_date', 'hire_date', 'status'];

    protected $table = 'persons';

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            $fields = ['f_name', 'l_name'];
            foreach ($fields as $field) {
                if ($model->isDirty($field) && ! empty($model->$field) && is_string($model->$field)) {
                    $model->$field = self::normalizeForSearch($model->$field);
                }
            }
        });

        // Invalidate cached HR stats whenever a person is created/updated/deleted (#341).
        // Dashboard and map caches also depend on person data, so bump those
        // version namespaces too.
        foreach (['saved', 'deleted'] as $event) {
            static::$event(function () {
                $cache = app(CacheInvalidationServiceInterface::class);
                foreach (['hr_stats', 'dashboard', 'maps'] as $namespace) {
                    $cache->increment($namespace);
                }
            });
        }
    }

    /**
     * Get the person's full name (f_name + l_name).
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->f_name} {$this->l_name}") ?: '—'
        );
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
