<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $name
 * @property int|null $parent_id
 * @property string|null $type
 * @property int|null $boundary_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Region|null $parent
 * @property-read Collection<int, Region> $children
 * @property-read Collection<int, Unit> $units
 * @property-read Boundary|null $boundary
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class Region extends Model
{
    protected $fillable = ['name', 'type', 'parent_id', 'boundary_id'];

    // رابطه با والد (برای شهرستان‌ها، استان والد است)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_id');
    }

    // رابطه با فرزندان (برای استان‌ها، شهرستان‌ها فرزندان هستند)
    public function children(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_id');
    }

    // رابطه با واحدهای سازمانی
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'region_id');
    }

    // رابطه با مرزها
    public function boundary(): BelongsTo
    {
        return $this->belongsTo(Boundary::class);
    }
}
