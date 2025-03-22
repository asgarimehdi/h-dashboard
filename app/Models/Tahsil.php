<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tahsil extends Model
{
    protected $fillable = ['name'];
    public function person():hasMany
    {
        return $this->hasMany(Person::class);
    }
}
