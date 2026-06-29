<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Unit extends Model
{
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
        return $this->hasMany(Person::class);
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

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $results = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id FROM units WHERE id IN ({$placeholders})
                UNION ALL
                SELECT u.id FROM units u
                INNER JOIN unit_tree ut ON u.parent_id = ut.id
                WHERE u.is_active = 1
            )
            SELECT id FROM unit_tree
        ", $ids);

        return collect($results)->pluck('id');
    }
}