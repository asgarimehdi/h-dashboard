<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'region_id', // جایگزین province_id و county_id
        'parent_id',
        'unit_type_id',
        'boundary_id',
        'lat',
        'lng',
    ];

    public function person(): HasMany
    {
        return $this->hasMany(Person::class, 'u_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    // رابطه با منطقه (استان یا شهرستان)
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    // رابطه برای ساختار سلسله‌مراتب: والد
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_id');
    }

    // رابطه برای ساختار سلسله‌مراتب: فرزندان
    public function children(): HasMany
    {
        return $this->hasMany(Unit::class, 'parent_id');
    }

    public function boundary(): BelongsTo
    {
        return $this->belongsTo(Boundary::class, 'boundary_id');
    }

    // برای بارگذاری تمام سطوح زیرمجموعه به صورت خودکار
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_units')
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }

    /**
     * Get ancestor (parent) IDs for a set of unit IDs — single JOIN query.
     *
     * @param  array<int>  $unitIds
     * @return Collection<int>
     */
    public static function ancestorIds(array $unitIds): Collection
    {
        $ids = array_values(array_filter($unitIds));

        if (empty($ids)) {
            return collect();
        }

        // Cache by sorted IDs to get consistent results regardless of input order
        $cache = app(CacheInvalidationServiceInterface::class);
        $version = $cache->getVersion('unit_hierarchy');
        $cacheKey = 'unit_ancestors:v'.$version.':'.md5(implode(',', array_map('strval', $ids)));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(15), // Same TTL as descendantIds
            fn () => self::ancestorQuery($ids)
        );
    }

    /**
     * Run the JOIN query for ancestor IDs.
     */
    protected static function ancestorQuery(array $ids): Collection
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $results = DB::select("
            SELECT DISTINCT parent.id
            FROM units base
            INNER JOIN units parent ON parent.id = base.parent_id
            WHERE base.id IN ({$placeholders})
        ", $ids);

        return collect($results)->pluck('id');
    }

    /**
     * تمام id های زیرمجموعه (شامل خود واحدهای ورودی) با Recursive CTE
     *
     * @param  int|array<int>  $unitIds
     */
    public static function descendantIds(int|array $unitIds): Collection
    {
        $ids = is_array($unitIds) ? $unitIds : [$unitIds];

        if (empty($ids)) {
            return collect();
        }

        // Cache by sorted IDs to get consistent results regardless of input order
        $cache = app(CacheInvalidationServiceInterface::class);
        $version = $cache->getVersion('unit_hierarchy');
        $cacheKey = 'unit_descendants:v'.$version.':'.md5(implode(',', array_map('strval', $ids)));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(15), // Shorter TTL to catch changes faster
            fn () => self::recursiveDescendantQuery($ids)
        );
    }

    /**
     * Run the recursive CTE query for descendant IDs.
     */
    protected static function recursiveDescendantQuery(array $ids): Collection
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $results = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id FROM units WHERE id IN ({$placeholders})
                UNION ALL
                SELECT u.id FROM units u
                INNER JOIN unit_tree ut ON u.parent_id = ut.id
                WHERE u.is_active = true
            )
            SELECT id FROM unit_tree
        ", $ids);

        return collect($results)->pluck('id');
    }

    /**
     * Scope for spatial queries: find units within a bounding box
     * Uses the composite lat/lng index for fast filtering
     */
    public function scopeWithinBounds($query, float $minLat, float $maxLat, float $minLng, float $maxLng)
    {
        return $query->whereBetween('lat', [$minLat, $maxLat])
            ->whereBetween('lng', [$minLng, $maxLng]);
    }

    /**
     * Scope for spatial queries: find units within a radius of a point
     * Uses the composite lat/lng index for fast filtering
     */
    public function scopeNearby($query, float $lat, float $lng, float $radiusKm = 10)
    {
        // Approximate degrees per km (rough estimate for Iran region)
        $degPerKm = 0.009;
        $delta = $radiusKm * $degPerKm;

        return $query->whereBetween('lat', [$lat - $delta, $lat + $delta])
            ->whereBetween('lng', [$lng - $delta, $lng + $delta]);
    }

    /**
     * Scope for spatial queries: find units whose boundary contains a point
     * Uses the spatial index on boundaries.boundary
     * Optimized with EXISTS to better utilize spatial index
     */
    public function scopeContainingPoint($query, float $lat, float $lng)
    {
        return $query->whereExists(function ($q) use ($lat, $lng) {
            $q->from('boundaries')
                ->whereColumn('boundaries.unit_id', 'units.id')
                ->whereRaw('ST_Contains(boundary, ST_GeomFromText(?, 4326))', ["POINT($lng $lat)"]);
        });
    }

    /**
     * Scope for spatial queries: find units whose boundary intersects with a polygon
     * Uses the spatial index on boundaries.boundary
     * Optimized with EXISTS to better utilize spatial index
     */
    public function scopeIntersectsBoundary($query, string $wktPolygon)
    {
        return $query->whereExists(function ($q) use ($wktPolygon) {
            $q->from('boundaries')
                ->whereColumn('boundaries.unit_id', 'units.id')
                ->whereRaw('ST_Intersects(boundary, ST_GeomFromText(?, 4326))', [$wktPolygon]);
        });
    }

    /**
     * Scope: filter to only descendants of a given unit (inclusive) using the
     * existing descendantIds() CTE with caching.
     */
    public function scopeSubtree($query, int $unitId)
    {
        $descendantIds = self::descendantIds($unitId)->all();

        return $query->whereIn('units.id', $descendantIds);
    }

    /**
     * Scope: add withCount('person as personnel_count') in a chainable way.
     */
    public function scopeWithPersonnelCount($query)
    {
        return $query->withCount('person as personnel_count');
    }

    /**
     * Scope for spatial queries: find units within a distance of a point (using ST_Distance_Sphere)
     * More accurate than bounding box but may be slower without proper spatial index
     */
    public function scopeWithinDistance($query, float $lat, float $lng, float $radiusMeters)
    {
        // MySQL/MariaDB: Use ST_GeomFromText instead of ST_Point (PostGIS-specific)
        $pointWkt = "POINT($lng $lat)";

        return $query->whereHas('boundary', function ($q) use ($pointWkt, $radiusMeters) {
            $q->whereRaw('ST_Distance_Sphere(boundary, ST_GeomFromText(?, 4326)) <= ?', [$pointWkt, $radiusMeters]);
        });
    }
}
