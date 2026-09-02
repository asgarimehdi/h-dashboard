<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use App\Traits\PersianNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Hardware extends Model
{
    use HasFactory;
    use PersianNormalizer;

    /**
     * Flag to suppress audit logging during bulk operations.
     */
    public static bool $suppressAudit = false;

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
        $cache->increment('gis'); // Hardware changes affect GIS data
        $cache->increment('maps'); // Hardware positions affect the map
        $cache->increment('dashboard'); // Hardware counts feed the dashboard
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

    // ── Query Scopes ──────────────────────────────────────────────────────

    /**
     * Filter by general search term (pc_name, n_code, IPs, MAC, comments, person name).
     */
    public function scopeFilterSearch($query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        $s = self::normalizeForSearch($term);

        $query->where(function ($q) use ($s) {
            $q->where('hardwares.pc_name', 'LIKE', "%{$s}%")
                ->orWhere('hardwares.n_code', 'LIKE', "%{$s}%")
                ->orWhere('hardwares.ip_valid', 'LIKE', "%{$s}%")
                ->orWhere('hardwares.ip_local', 'LIKE', "%{$s}%")
                ->orWhere('hardwares.mac', 'LIKE', "%{$s}%")
                ->orWhere('hardwares.comments', 'LIKE', "%{$s}%")
                ->orWhere('persons.f_name', 'LIKE', "%{$s}%")
                ->orWhere('persons.l_name', 'LIKE', "%{$s}%")
                ->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
        });
    }

    /**
     * Filter by hardware type (with alias mapping).
     */
    public function scopeFilterType($query, ?string $type): void
    {
        if (! $type) {
            return;
        }

        $typeAliases = ['desktop' => 'pc', 'پی‌سی' => 'pc'];
        $type = $typeAliases[$type] ?? $type;

        $query->where('hardwares.type', 'LIKE', "%{$type}%");
    }

    /**
     * Filter by OS.
     */
    public function scopeFilterOs($query, ?string $os): void
    {
        if ($os) {
            $query->where('hardwares.os', 'LIKE', "%{$os}%");
        }
    }

    /**
     * Filter by CPU.
     */
    public function scopeFilterCpu($query, ?string $cpu): void
    {
        if ($cpu) {
            $query->where('hardwares.cpu', 'LIKE', "%{$cpu}%");
        }
    }

    /**
     * Filter by RAM.
     */
    public function scopeFilterRam($query, ?string $ram): void
    {
        if ($ram) {
            $query->where('hardwares.ram', 'LIKE', "%{$ram}%");
        }
    }

    /**
     * Filter by HDD.
     */
    public function scopeFilterHdd($query, ?string $hdd): void
    {
        if ($hdd) {
            $query->where('hardwares.hdd', 'LIKE', "%{$hdd}%");
        }
    }

    /**
     * Filter by shutdown status.
     */
    public function scopeFilterShutdown($query, ?string $shutdown): void
    {
        if ($shutdown !== null && $shutdown !== '') {
            $query->where('hardwares.shutdown', $shutdown === 'true' || $shutdown === '1');
        }
    }

    /**
     * Filter by network type.
     */
    public function scopeFilterNetType($query, ?string $netType): void
    {
        if ($netType) {
            $query->where('hardwares.net_type', 'LIKE', "%{$netType}%");
        }
    }

    /**
     * Filter by mark status.
     */
    public function scopeFilterMark($query, ?string $mark): void
    {
        if ($mark !== null && $mark !== '') {
            $query->where('hardwares.mark', $mark === 'true' || $mark === '1');
        }
    }

    /**
     * Filter by person name/n_code (searches persons table via join).
     */
    public function scopeFilterPerson($query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        $normalized = self::normalizeForSearch($term);

        $query->where(function ($q) use ($normalized) {
            $q->where('persons.f_name', 'LIKE', "%{$normalized}%")
                ->orWhere('persons.l_name', 'LIKE', "%{$normalized}%")
                ->orWhere('persons.n_code', 'LIKE', "%{$normalized}%")
                ->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$normalized}%"]);
        });
    }

    /**
     * Filter by unit name (via persons.u_id → units).
     */
    public function scopeFilterUnit($query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        $normalized = self::normalizeForSearch($term);

        $query->whereExists(function ($q) use ($normalized) {
            $q->selectRaw('1')
                ->from('units')
                ->whereColumn('units.id', 'persons.u_id')
                ->where('units.name', 'LIKE', "%{$normalized}%");
        });
    }

    /**
     * Filter by semat (job title) name (via persons.s_id → semats).
     */
    public function scopeFilterSemat($query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        $normalized = self::normalizeForSearch($term);

        $query->whereExists(function ($q) use ($normalized) {
            $q->selectRaw('1')
                ->from('semats')
                ->whereColumn('semats.id', 'persons.s_id')
                ->where('semats.name', 'LIKE', "%{$normalized}%");
        });
    }
}
