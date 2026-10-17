<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $hardware_id
 * @property int $user_id
 * @property string $action
 * @property array<string, mixed> $changes
 * @property string $source
 * @property string $ip_address
 * @property string $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Hardware $hardware
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $value)
 */
class HardwareAudit extends Model
{
    protected $fillable = [
        'hardware_id',
        'user_id',
        'action',
        'changes',
        'source',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    /**
     * Every audit action that means "this device is deleted". The trash reads
     * this one list in both of its delete queries (#1026), so the web path's
     * `bulk_delete` and the observer's `deleted` can never drift apart again.
     *
     * @var list<string>
     */
    public const DELETING_ACTIONS = ['deleted', 'bulk_delete'];

    public function hardware(): BelongsTo
    {
        return $this->belongsTo(Hardware::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
