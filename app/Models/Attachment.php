<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = ['user_id' ,'file_path', 'file_name', 'file_size','ticket_id', 'activity_id'];


public function user()
{
    return $this->belongsTo(User::class);
}
public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
