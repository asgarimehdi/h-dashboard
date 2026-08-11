<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

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
                if ($model->isDirty($field) && ! empty($model->$field) && is_string($model->$field)) {
                    $model->$field = self::normalizeForSearch($model->$field);
                }
            }
        });

        // Issue #217: invalidate cached hardware stats on any write (create/update/delete).
        // Uses a wildcard-friendly prefix so all per-user scope keys are cleared at once.
        static::saved(fn () => self::flushStatsCache());
        static::deleted(fn () => self::flushStatsCache());
    }

    /**
     * Invalidate all cached hardware stats (any organizational scope).
     *
     * Stats cache keys are `hardware_stats:v<N>:<md5(accessibleIds)>`.
     * A write may affect any scope, so bumping the version counter makes all
     * previously cached scope keys unreachable; they expire naturally via TTL.
     * This is driver-agnostic (array/file/redis all support increment) and
     * avoids flushing unrelated cached data (access units, notifications...).
     */
    public static function flushStatsCache(): void
    {
        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('hardware_stats');
        $cache->increment('gis'); // Issue #373: imports also change map data
        $cache->increment('maps'); // Issue #394
        $cache->increment('dashboard'); // Issue #394
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'n_code', 'n_code');
    }

    /**
     * Audit trail (unified, Issue #246) — replaces the old histories relation.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(HardwareAudit::class);
    }

    /**
     * @deprecated Use audits() instead (Issue #246 merge).
     */
    public function histories(): HasMany
    {
        return $this->hasMany(HardwareAudit::class, 'hardware_id');
    }
}
