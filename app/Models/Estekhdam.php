<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estekhdam extends Model
{
    protected $fillable = ['name'];

    public function person(): HasMany
    {
        return $this->hasMany(Person::class, 'e_id');
    }
}
