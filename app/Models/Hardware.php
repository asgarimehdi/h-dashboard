<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use App\Support\HardwareFilters;
use App\Traits\PersianNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $n_code
 * @property string $pc_name
 * @property string $type
 * @property string $os
 * @property string $ip_valid
 * @property string $ip_local
 * @property string $mac
 * @property string $net_type
 * @property string $switch
 * @property string $port
 * @property bool $shutdown
 * @property string $vlan
 * @property string $motherboard
 * @property string $cpu
 * @property string $ram
 * @property string $hdd
 * @property string $comments
 * @property bool $mark
 * @property Carbon|null $clean_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Person|null $person
 * @property-read Collection<int, HardwareAudit> $audits
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
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

    // ── Query Scopes ──────────────────────────────────────────────────────
    //
    // Issue #1050: every filter now delegates to `HardwareFilters`, the single
    // implementation shared with the export controller and the Livewire
    // component. These wrappers remain so existing callers (the API controller
    // and the tests) keep the `filterX()` entry points.

    /**
     * Filter by general search term (pc_name, n_code, IPs, MAC, comments, person name).
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterSearch(Builder $query, ?string $term): void
    {
        HardwareFilters::applySearch($query, $term);
    }

    /**
     * Filter by hardware type (with alias mapping).
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterType(Builder $query, ?string $type): void
    {
        HardwareFilters::applyType($query, $type);
    }

    /**
     * Filter by OS.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterOs(Builder $query, ?string $os): void
    {
        HardwareFilters::applyText($query, 'os', $os);
    }

    /**
     * Filter by CPU.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterCpu(Builder $query, ?string $cpu): void
    {
        HardwareFilters::applyText($query, 'cpu', $cpu);
    }

    /**
     * Filter by RAM.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterRam(Builder $query, ?string $ram): void
    {
        HardwareFilters::applyText($query, 'ram', $ram);
    }

    /**
     * Filter by HDD.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterHdd(Builder $query, ?string $hdd): void
    {
        HardwareFilters::applyText($query, 'hdd', $hdd);
    }

    /**
     * Filter by shutdown status.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterShutdown(Builder $query, ?string $shutdown): void
    {
        HardwareFilters::applyBoolean($query, 'shutdown', $shutdown);
    }

    /**
     * Filter by network type.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterNetType(Builder $query, ?string $netType): void
    {
        HardwareFilters::applyText($query, 'net_type', $netType);
    }

    /**
     * Filter by mark status.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterMark(Builder $query, ?string $mark): void
    {
        HardwareFilters::applyBoolean($query, 'mark', $mark);
    }

    /**
     * Filter by person name/n_code.
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterPerson(Builder $query, ?string $term): void
    {
        HardwareFilters::applyPerson($query, $term);
    }

    /**
     * Filter by unit name (via persons.u_id → units).
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterUnit(Builder $query, ?string $term): void
    {
        HardwareFilters::applyUnit($query, $term);
    }

    /**
     * Filter by semat (job title) name (via persons.s_id → semats).
     *
     * @param  Builder<Hardware>  $query
     */
    public function scopeFilterSemat(Builder $query, ?string $term): void
    {
        HardwareFilters::applySemat($query, $term);
    }
}
