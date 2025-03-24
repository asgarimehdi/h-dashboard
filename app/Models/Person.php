<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Person extends Model
{
    protected $fillable = ['n_code','f_name','l_name','t_id', 'e_id', 'r_id', 's_id', 'u_id',];
    protected $table = 'persons'; // chon person kalame khas ast

    public function user(): belongsTo
    {
        return $this->belongsTo(User::class,'n_code','n_code');
    }
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