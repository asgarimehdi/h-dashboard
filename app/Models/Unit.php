<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use App\Support\ScopeCacheKey;
use App\Support\UnitAncestry;
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
     * @param  array<int>|null  $accessibleIds  If null, no scope filter applied; an empty array means "in scope of nothing" and intersects to zero rows
     * @return Collection<int, static>
     */
    public static function buildTree(array $rootIds, ?array $accessibleIds = null): Collection
    {
        if (empty($rootIds)) {
            return collect();
        }

        // Single CTE: fetch all descendants of root units (inclusive)
        $allIds = self::descendantIds($rootIds)->all();

        if ($accessibleIds !== null) {
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

        // Recursive closure to attach children. #1350: a parent_id cycle
        // reachable from a root recursed forever here. The guard reuses the
        // UnitAncestry contract: a per-path visited set seeded with the root,
        // the MAX_DEPTH safety cap, and a silent cut — a repeated child is
        // not attached, nothing is thrown, and no marker is emitted. The
        // root itself attaches; only the back edge is cut. The CTE, the
        // scope intersect, and the eager load are untouched.
        $attachChildren = function (Unit $unit, array $visited, int $depth) use (&$attachChildren, $childrenMap): void {
            $children = [];
            foreach ($childrenMap[$unit->id] ?? [] as $child) {
                if (isset($visited[$child->id]) || $depth + 1 >= UnitAncestry::MAX_DEPTH) {
                    continue;
                }
                $nextVisited = $visited;
                $nextVisited[$child->id] = true;
                $attachChildren($child, $nextVisited, $depth + 1);
                $children[] = $child;
            }
            $unit->childrenRecursive = collect($children);
        };

        // Build root collection
        $roots = collect();
        foreach ($rootIds as $rootId) {
            if (isset($allUnits[$rootId])) {
                $roots[] = $allUnits[$rootId];
            }
        }

        foreach ($roots as $root) {
            $attachChildren($root, [$root->id => true], 0);
        }

        return $roots;
    }

    /**
     * Scope-rooted unit tree for the units-chart page, cached under
     * `unit_hierarchy` (#1999).
     *
     * Key = the accessible-id SET (ScopeCacheKey), not the user: the tree
     * carries no user data. `extra` keeps this entry disjoint from
     * OrgChartController::loadSubtree() and OptionCatalog::units(), which
     * share the same namespace + scope hash with their own `extra`.
     *
     * Invalidation: Unit::created/updated/deleted (AppServiceProvider) and
     * UnitType::booted() bump `unit_hierarchy`, so create/update/delete/
     * reparent/rename/type-change makes the entry unreachable. TTL 15 min
     * mirrors descendantIds(). Bulk DB::table() writes bypass model events —
     * same known limit as descendantIds(); the TTL bounds it.
     *
     * @param  array<int>  $accessibleIds
     * @return Collection<int, static>
     */
    public static function treeForScope(array $accessibleIds): Collection
    {
        /** @var Collection<int, static> $tree */
        $tree = app(CacheInvalidationServiceInterface::class)->remember(
            'unit_hierarchy',
            ScopeCacheKey::hash($accessibleIds),
            function () use ($accessibleIds): Collection {
                $rootIds = self::query()
                    ->whereNull('parent_id')
                    ->whereIn('id', $accessibleIds)
                    ->pluck('id')
                    ->all();

                return self::buildTree($rootIds, $accessibleIds);
            },
            15,
            ['surface' => 'units-chart'],
        );

        return $tree;
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
        $sorted = array_map('strval', $ids);
        sort($sorted, SORT_STRING);
        $cacheKey = 'unit_ancestors:v'.$version.':'.md5(implode(',', $sorted));

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
     * کل زنجیرهٔ اجداد (همهٔ سطوح) برای یک مجموعه id — یک Recursive CTE.
     *
     * برخلاف ancestorIds() که فقط والد مستقیم را می‌دهد، این متد تا ریشه
     * بالا می‌رود. خود idهای ورودی فقط اگر جدِ ورودی دیگری باشند در نتیجه
     * می‌آیند (هم‌رفتار با expandParents قدیم در چارت واحدها).
     *
     * UNION (نه UNION ALL) عمدی است: چرخهٔ parent_id را خاتمه می‌دهد؛
     * UNION ALL روی چرخه تا ابد می‌چرخد. بی‌کش: مجموعهٔ idها هر جستجو
     * متفاوت است، پس کلید کش تقریباً هرگز hit نمی‌شود.
     *
     * @param  array<int>  $unitIds
     * @return Collection<int, int>
     */
    public static function ancestorChainIds(array $unitIds): Collection
    {
        $ids = array_values(array_filter($unitIds));

        if (empty($ids)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $results = DB::select("
            WITH RECURSIVE unit_ancestors AS (
                SELECT p.id, p.parent_id
                FROM units p
                INNER JOIN units base ON base.parent_id = p.id
                WHERE base.id IN ({$placeholders})
                UNION
                SELECT p.id, p.parent_id
                FROM units p
                INNER JOIN unit_ancestors a ON p.id = a.parent_id
            )
            SELECT DISTINCT id FROM unit_ancestors
        ", $ids);

        return collect($results)->pluck('id')->map(fn ($id): int => (int) $id)->values();
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
        $sorted = array_map('strval', $ids);
        sort($sorted, SORT_STRING);
        $cacheKey = 'unit_descendants:v'.$version.':'.md5(implode(',', $sorted));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(15), // Shorter TTL to catch changes faster
            fn () => self::recursiveDescendantQuery($ids)
        );
    }

    /**
     * تمام id های زیرمجموعه ساختاری (شامل خود واحدهای ورودی) — بدون فیلتر is_active.
     *
     * فقط برای گارد چرخهٔ parent_id (#1737). برای scope/گزارش از descendantIds()
     * استفاده کنید: یک scope باید فقط-فعال بماند و این متد واحدهای بازنشسته را
     * هم برمی‌گرداند.
     *
     * عمداً بی‌کش: کلید descendantIds() فیلتر را در خود ندارد، پس اشتراک کش
     * دو مجموعهٔ متفاوت را زیر یک کلید قاطی می‌کند. هزینه یک کوئری در هر
     * نوشتنِ واحد است.
     *
     * @param  int|array<int>  $unitIds
     * @return Collection<int, int>
     */
    public static function structuralDescendantIds(int|array $unitIds): Collection
    {
        $ids = is_array($unitIds) ? $unitIds : [$unitIds];

        if (empty($ids)) {
            return collect();
        }

        return self::recursiveDescendantQuery($ids, onlyActive: false);
    }

    /**
     * Run the recursive CTE query for descendant IDs.
     *
     * UNION (not UNION ALL) is load-bearing: the set operator dedupes, so a
     * parent_id cycle terminates instead of recursing forever. UNION ALL on a
     * cyclic parent_id hangs the connection — parent_id is user-editable and
     * the units_parent_fk foreign key blocks dangling references, not cycles,
     * so the web form's type guard is not the only possible writer.
     */
    protected static function recursiveDescendantQuery(array $ids, bool $onlyActive = true): Collection
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // UNION matters more when $onlyActive is false: the traversal then
        // walks THROUGH inactive nodes, so a pre-existing cycle that passes
        // through one is reachable and only the set operator terminates it.
        $activeFilter = $onlyActive ? 'WHERE u.is_active = true' : '';

        $results = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id FROM units WHERE id IN ({$placeholders})
                UNION
                SELECT u.id FROM units u
                INNER JOIN unit_tree ut ON u.parent_id = ut.id
                {$activeFilter}
            )
            SELECT id FROM unit_tree
        ", $ids);

        return collect($results)->pluck('id')->map(fn ($id): int => (int) $id);
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
