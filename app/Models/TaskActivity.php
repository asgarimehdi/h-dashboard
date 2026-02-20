<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskActivity extends Model
{
  protected $fillable = [
    'ticket_id', 
    'user_id',
    'action',
    'description',
    'is_internal',
    'to_unit_id', 
    'to_user_id'
];

   

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
{
  
    return $this->hasMany(Attachment::class, 'activity_id');
}
}
