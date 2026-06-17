<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
        protected $fillable = [
            
            'title','start_at','end_at','is_completed'

    ];
}
