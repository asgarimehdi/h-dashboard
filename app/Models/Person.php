<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// --->>> اضافه شد
use Illuminate\Database\Eloquent\Relations\HasOne;

class Person extends Model
{
    protected $fillable = ['n_code','f_name','l_name','t_id', 'e_id', 'r_id', 's_id', 'u_id',];
    protected $table = 'persons';

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
