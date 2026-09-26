<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $region_id
 * @property int|null $parent_id
 * @property string $name
 * @property int|null $unit_type_id
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $description
 * @property int|null $boundary_id
 * @property int|null $personnel_count
 * @property bool $is_active
 * @property bool $can_receive_tickets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property mixed $geom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, static> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Person> $person
 * @property-read UnitType|null $unitType
 * @property-read Region|null $region
 * @property-read static|null $parent
 * @property-read Boundary|null $boundary
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Ticket> $tickets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Todo> $todos
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $assignedUsers
 * @property Collection<int, static> $childrenRecursive
 *
 * @method static Builder<static> where(string $column, mixed $value)
 * @method static Builder<static> withinBounds(float $minLat, float $maxLat, float $minLng, float $maxLng)
 * @method static Builder<static> nearby(float $lat, float $lng, float $radiusKm = 10)
 * @method static Builder<static> containingPoint(float $lat, float $lng)
 * @method static Builder<static> intersectsBoundary(string $wktPolygon)
 * @method static Builder<static> subtree(int $unitId)
 * @method static Builder<static> withPersonnelCount()
 * @method static Builder<static> withinDistance(float $lat, float $lng, float $radiusMeters)
 */
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
        'is_active',
        'can_receive_tickets',
    ];

    protected $casts = [
        'can_receive_tickets' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Attributes that should be cast.
     * Using this instead of $attributes default since boolean cast
     * does not apply when the attribute isn't set by Eloquent.
     */
    protected $attributes = [
        'can_receive_tickets' => false,
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

    /**
     * Build full tree structure from a flat collection using a single CTE query.
     * Replaces the N+1 recursive eager loading pattern (childrenRecursive).
     *
     * @param  array<int>  $rootIds
     * @param  array<int>|null  $accessibleIds  If null, no scope filter applied
     * @return Collection<int, static>
     */
    public static function buildTree(array $rootIds, ?array $accessibleIds = null): Collection
    {
        if (empty($rootIds)) {
            return collect();
        }

        // Single CTE: fetch all descendants of root units (inclusive)
        $allIds = self::descendantIds($rootIds)->all();

        if (! empty($accessibleIds)) {
            $allIds = array_values(array_intersect($allIds, $accessibleIds));
        }

        if (empty($allIds)) {
            return collect();
        }

        // Single query: load all relevant units with their types
        $models = self::query()
            ->with('unitType')
            ->whereIn('units.id', $allIds)
            ->get();

        /** @var array<int, static> $allUnits */
        $allUnits = [];
        /** @var array<int, list<static>> $childrenMap */
        $childrenMap = [];
        foreach ($models as $unit) {
            $allUnits[$unit->id] = $unit;
        }

        // Build adjacency list
        foreach ($allUnits as $unit) {
            $parentId = $unit->parent_id;
            if ($parentId !== null && isset($allUnits[$parentId])) {
                $childrenMap[$parentId][] = $unit;
            }
        }

        // Recursive closure to attach children
        $attachChildren = function (Unit $unit) use (&$attachChildren, $childrenMap): void {
            $unit->childrenRecursive = collect($childrenMap[$unit->id] ?? []);
            foreach ($unit->childrenRecursive as $child) {
                $attachChildren($child);
            }
        };

        // Build root collection
        $roots = collect();
        foreach ($rootIds as $rootId) {
            if (isset($allUnits[$rootId])) {
                $roots[] = $allUnits[$rootId];
            }
        }

        foreach ($roots as $root) {
            $attachChildren($root);
        }

        return $roots;
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
     *
     * UNION (not UNION ALL) is load-bearing: the set operator dedupes, so a
     * parent_id cycle terminates instead of recursing forever. UNION ALL on a
     * cyclic parent_id hangs the connection — parent_id is user-editable and
     * not constrained by the database, so the web form's type guard is not the
     * only possible writer.
     */
    protected static function recursiveDescendantQuery(array $ids): Collection
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $results = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id FROM units WHERE id IN ({$placeholders})
                UNION
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
        // The unit↔boundary link is units.boundary_id (boundaries has no unit_id).
        return $query->whereHas('boundary', function ($q) use ($lat, $lng) {
            $q->whereRaw('ST_Contains(boundary, ST_GeomFromText(?, 4326))', ["POINT($lng $lat)"]);
        });
    }

    /**
     * Scope for spatial queries: find units whose boundary intersects with a polygon
     * Uses the spatial index on boundaries.boundary
     */
    public function scopeIntersectsBoundary($query, string $wktPolygon)
    {
        return $query->whereHas('boundary', function ($q) use ($wktPolygon) {
            $q->whereRaw('ST_Intersects(boundary, ST_GeomFromText(?, 4326))', [$wktPolygon]);
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
        // PostGIS function name is ST_DistanceSphere (no underscore); the old
        // MySQL-style ST_Distance_Sphere does not exist on this stack.
        $pointWkt = "POINT($lng $lat)";

        return $query->whereHas('boundary', function ($q) use ($pointWkt, $radiusMeters) {
            $q->whereRaw('ST_DistanceSphere(boundary, ST_GeomFromText(?, 4326)) <= ?', [$pointWkt, $radiusMeters]);
        });
    }

    /**
     * تیکت‌های مرتبط با این واحد.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * وظایف (todos) مرتبط با این واحد.
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }
}
