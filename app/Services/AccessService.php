<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AccessService
{
    /**
     * واحدهای قابل دسترس کاربر (شامل خودش + تمام زیرمجموعه‌ها)
     *
     * @return array<int>
     */
    public function accessibleUnitIds(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            return [];
        }

        $currentUnitId = session('current_unit_id');

        if ($currentUnitId) {
            $baseUnitIds = [$currentUnitId];
        } else {
            $baseUnitIds = $user->units()->pluck('units.id')->toArray();

            if (empty($baseUnitIds)) {
                $personUnitId = $user->person?->u_id;
                if ($personUnitId) {
                    $baseUnitIds = [$personUnitId];
                }
            }
        }

        if (empty($baseUnitIds)) {
            return [];
        }

        $cacheKey = "accessible_units:{$user->id}:".md5(json_encode($baseUnitIds));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            fn () => Unit::descendantIds($baseUnitIds)->toArray()
        );
    }

    /**
     * پاک کردن کش دسترسی کاربر (هنگام تغییر context یا تغییر واحدها)
     */
    public function clearCache(?User $user = null): void
    {
        $user ??= auth()->user();

        if ($user) {
            $currentUnitId = session('current_unit_id');
            $baseUnitIds = $currentUnitId
                ? [$currentUnitId]
                : $user->units()->pluck('units.id')->toArray();

            $cacheKey = "accessible_units:{$user->id}:".md5(json_encode($baseUnitIds));
            Cache::forget($cacheKey);
        }
    }
}
