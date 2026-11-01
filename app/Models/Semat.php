<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Person> $person
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class Semat extends Model
{
    protected $fillable = ['name'];

    protected static function boot(): void
    {
        parent::boot();

        // A rename changes the `details` names in the cached persons report
        // (#1351), so the lookup models bump `report_persons` like Person
        // bumps its own namespaces. Seeders write through `create`, so they
        // are covered automatically.
        static::saved(function () {
            $cache = app(CacheInvalidationServiceInterface::class);
            $cache->increment('report_persons');
        });

        static::deleted(function () {
            $cache = app(CacheInvalidationServiceInterface::class);
            $cache->increment('report_persons');
        });
    }

    public function person(): HasMany
    {
        return $this->hasMany(Person::class, 's_id');
    }
}
