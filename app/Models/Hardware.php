<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hardware extends Model
{
    protected $table = 'hardwares';

    protected $fillable = [
        'n_code',
        'pc_name',
        'type',
        'os',
        'ip_valid',
        'ip_local',
        'mac',
        'net_type',
        'switch',
        'port',
        'shutdown',
        'vlan',
        'motherboard',
        'cpu',
        'ram',
        'hdd',
        'comments',
        'mark',
        'clean_at',
    ];

    protected $casts = [
        'shutdown' => 'boolean',
        'mark' => 'boolean',
        'clean_at' => 'date',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'n_code', 'n_code');
    }
}
