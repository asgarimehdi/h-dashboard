<?php

namespace App\Models;

use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hardware extends Model
{
    use PersianNormalizer;

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

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            $fields = ['pc_name', 'type', 'os', 'cpu', 'ram', 'hdd', 'net_type', 'switch', 'vlan', 'motherboard', 'comments'];
            foreach ($fields as $field) {
                if ($model->isDirty($field) && !empty($model->$field) && is_string($model->$field)) {
                    $model->$field = self::normalizeForSearch($model->$field);
                }
            }
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'n_code', 'n_code');
    }
}
