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

        $sessionUnitId = session('current_unit_id', 'none');
        $cache = app(CacheInvalidationServiceInterface::class);
        $version = $cache->getVersion('unit_hierarchy');
        $sortedBase = $baseUnitIds;
        sort($sortedBase);
        $cacheKey = "accessible_units:v{$version}:{$user->id}:{$sessionUnitId}:".md5(json_encode($sortedBase));

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            fn () => Unit::descendantIds($baseUnitIds)->toArray()
        );
    }

    /**
     * تمام واحدهای سازمان، بدون وابستگی به کاربر یا نشست جاری
     *
     * `accessibleUnitIds()` به `auth()` و `session()` وابسته است، پس در
     * scheduler یا queue worker همیشه `[]` برمی‌گرداند و هر مصرف‌کننده‌ای
     * آن را «کاری برای انجام نیست» می‌خواند. نگهداشت داده (retention) و
     * تولید گزارش وظیفه سراسری‌اند، نه وابسته به اینکه چه کسی دکمه را
     * زده — وگرنه دو مدیر با دامنه‌های متفاوت دو برش متفاوت از یک جدول را
     * پاک می‌کردند. این متد جایگزین آن است، نه مکملش.
     *
     * عمداً بدون کش: یک کوئری ساده در مسیرهای کم‌تکرار (زمان‌بند و worker)
     * و بدون نیاز به نسخه‌بندی کش جدید در `PruneStaleCache::NAMESPACES`.
     *
     * @return array<int>
     */
    public function allUnitIds(): array
    {
        return Unit::query()
            ->get(['id'])
            ->map(fn (Unit $unit): int => $unit->id)
            ->all();
    }

    /**
     * پاک کردن کش دسترسی کاربر (هنگام تغییر context یا تغییر واحدها)
     */
    public function clearCache(?User $user = null): void
    {
        $user ??= auth()->user();

        if ($user) {
            // Bump the version counter so ANY versioned accessible_units key for this
            // hierarchy (including keys written under an older version) becomes
            // unreachable instead of surviving until TTL (fix for stale-cache bug).
            $cache = app(CacheInvalidationServiceInterface::class);
            $cache->increment('unit_hierarchy');

            $currentUnitId = session('current_unit_id');
            $sessionUnitId = session('current_unit_id', 'none');
            $baseUnitIds = $currentUnitId
                ? [$currentUnitId]
                : $user->units()->pluck('units.id')->toArray();

            // Forget the legacy unversioned key too.
            $cacheKey = "accessible_units:{$user->id}:{$sessionUnitId}:".md5(json_encode($baseUnitIds));
            Cache::forget($cacheKey);
        }
    }

    /**
     * پاک کردن تمام کش‌های دسترسی (هنگام تغییر سلسله‌مراتب واحدها)
     */
    public function clearAllCaches(): void
    {
        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('unit_hierarchy');
        $cache->increment('gis');
    }
}
