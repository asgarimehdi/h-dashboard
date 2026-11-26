<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $n_code
 * @property string $password
 * @property Carbon|null $email_verified_at
 * @property array<string, mixed> $settings
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $name
 * @property-read string $unit_name
 * @property-read Person|null $person
 * @property-read Unit|null $unit
 * @property-read Collection<int, Unit> $units
 *
 * @method static Builder<static> where(string $column, mixed $value)
 */
class User extends Authenticatable
{
    use HasApiTokens,HasFactory, Notifiable,SoftDeletes;

    // The User model requires this trait
    use HasRoles;

    protected $guard_name = 'web';

    protected $fillable = [
        'n_code',
        'password',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * دریافت اطلاعات Person مرتبط با این User.
     * چون کلید خارجی (n_code) در جدول users است، از belongsTo استفاده می‌کنیم.
     */
    public function person(): BelongsTo // <--- تغییر به BelongsTo
    {
        // پارامتر دوم: نام کلید خارجی در جدول users (این جدول)
        // پارامتر سوم: نام کلید مالک (کلید اصلی یا unique) در جدول persons
        return $this->belongsTo(Person::class, 'n_code', 'n_code'); // <--- تغییر به belongsTo
    }

    // Accessor ها به درستی از $this->person استفاده می‌کنند و نیازی به تغییر ندارند
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function () {
                // کلید یکتا برای هر کاربر
                $sessionKey = "user_{$this->id}_display_name";

                // اول از session بخوان
                if (($cached = session($sessionKey)) !== null) {
                    return $cached;
                }

                // دیتابیس — فقط اولین بار بعد از لاگین
                $person = $this->relationLoaded('person')
                    ? $this->person
                    : $this->person()->first();

                $name = $person
                    ? ($person->f_name.' '.$person->l_name)
                    : 'کاربر بدون پروفایل';

                // ذخیره در session — تا لاگ‌اوت
                session([$sessionKey => $name]);

                return $name;
            }
        );
    }

    public function getUnitNameAttribute()
    {
        return $this->person?->unit?->name ?? '-'; // استفاده از nullsafe operator
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'user_units')
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }

    public function primaryUnit(): ?Unit
    {
        return $this->units()->wherePivot('is_primary', true)->first();
    }

    /**
     * Make the given unit the user's one primary unit (#1803).
     *
     * This is the only sanctioned writer of `user_units.is_primary`:
     * the partial unique index `user_units_one_primary_per_user` allows
     * at most one primary row per user, so the old primary MUST be cleared
     * before the new one is set. The existing pivot `role` is preserved and
     * a missing pivot row is attached as `staff`.
     */
    public function setPrimaryUnit(Unit|int $unit): void
    {
        $unitId = $unit instanceof Unit ? (int) $unit->getKey() : $unit;

        DB::transaction(function () use ($unitId): void {
            $current = $this->units()->wherePivot('is_primary', true)->pluck('units.id')->all();

            if ($current !== []) {
                $this->units()->updateExistingPivot($current, ['is_primary' => false]);
            }

            if (! $this->units()->where('units.id', $unitId)->exists()) {
                $this->units()->attach($unitId, ['role' => 'staff', 'is_primary' => false]);
            }

            $this->units()->updateExistingPivot($unitId, ['is_primary' => true]);
        });
    }

    protected $hidden = ['password',
        'settings', 'remember_token'];

    /**
     * Derive API token abilities from the user's Spatie permissions.
     *
     * Maps each permission to its corresponding API ability(ies).
     * Admins (who have all permissions via RoleSeeder) get the full set.
     *
     * @return array<int, string>
     */
    public function getApiTokenAbilities(): array
    {
        // Issue #1002: mutations require their own write ability; every user
        // with notification access gets both (unconditional, like before).
        $abilities = ['notifications:read', 'notifications:write'];

        if ($this->hasPermissionTo('organization')) {
            $abilities[] = 'units:read';
            $abilities[] = 'units:write';
        }

        if ($this->hasPermissionTo('manage_hardware')) {
            $abilities[] = 'hardware:read';
            $abilities[] = 'hardware:write';
        }

        if ($this->hasAnyPermission(['view_assigned_tickets', 'view_all_tickets'])) {
            $abilities[] = 'tickets:read';
        }

        // #1867: `tickets:write` must cover every permission the ticket write
        // routes accept — update/destroy/assign/complete (and comment
        // update/destroy) are gated on `manage_unit_tickets`, not `create_ticket`.
        if ($this->hasAnyPermission(['create_ticket', 'manage_unit_tickets'])) {
            $abilities[] = 'tickets:write';
        }

        if ($this->hasAnyPermission(['kargozini', 'manage_personnel'])) {
            $abilities[] = 'persons:read';
        }

        if ($this->hasPermissionTo('manage_personnel')) {
            $abilities[] = 'persons:write';
        }

        if ($this->hasPermissionTo('calendar')) {
            $abilities[] = 'todos:read';
            $abilities[] = 'todos:write';
        }

        if ($this->hasPermissionTo('view_hr_dashboard')) {
            $abilities[] = 'hr:read';
        }

        if ($this->hasAnyPermission(['map', 'bw'])) {
            $abilities[] = 'traffic:read';
            $abilities[] = 'gis:read';
        }

        if ($this->hasPermissionTo('manage_personnel')) {
            $abilities[] = 'reports:read';
        }

        return $abilities;
    }
}
