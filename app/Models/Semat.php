<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semat extends Model
{
    public function person():hasMany
    {
        return $this->hasMany(Person::class);
    }
}
