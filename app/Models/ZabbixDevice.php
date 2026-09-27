<?php

namespace App\Models;

use App\Services\CacheInvalidationServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A monitored Zabbix device (issue #698) — replaces the item-ID arrays that
 * used to be hardcoded in `it/networks` and `it/wireless`.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $out_item_id
 * @property string|null $in_item_id
 * @property string|null $signal_item_id
 * @property string|null $frequency_item_id
 * @property string|null $response_item_id
 * @property int $initial_duration
 * @property float|null $min
 * @property float|null $max
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> ordered()
 * @method static Builder<static> ofType(string $type)
 * @method static Builder<static> where(string $column, mixed $value)
 */
class ZabbixDevice extends Model
{
    use HasFactory;

    public const TYPE_NETWORK = 'network';

    public const TYPE_WIRELESS = 'wireless';

    /** Cache namespace holding the rendered device lists (see issue #698). */
    public const CACHE_NAMESPACE = 'zabbix_devices';

    protected $fillable = [
        'name',
        'type',
        'out_item_id',
        'in_item_id',
        'signal_item_id',
        'frequency_item_id',
        'response_item_id',
        'initial_duration',
        'min',
        'max',
        'sort_order',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'initial_duration' => 'integer',
        'min' => 'float',
        'max' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Any write changes what the display pages render — bump the version
        // counter so every cached device list becomes unreachable at once.
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    public static function flushCache(): void
    {
        app(CacheInvalidationServiceInterface::class)->increment(self::CACHE_NAMESPACE);
    }

    /**
     * Soft-hide without deleting.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $query->where('is_active', true);

        return $query;
    }

    /**
     * Display order: `sort_order`, ties broken by insertion order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        $query->orderBy('sort_order');
        $query->orderBy('id');

        return $query;
    }

    /**
     * Which display page renders the row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        $query->where('type', $type);

        return $query;
    }

    /**
     * Every non-empty item ID on this device — what the connection test sends
     * to Zabbix.
     *
     * @return array<int, string>
     */
    public function itemIds(): array
    {
        return array_values(array_filter([
            $this->out_item_id,
            $this->in_item_id,
            $this->signal_item_id,
            $this->frequency_item_id,
            $this->response_item_id,
        ], fn ($id) => $id !== null && $id !== ''));
    }
}
