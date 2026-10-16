<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AccessService
{
    /**
     * Request-level memo of `accessibleUnitIds()`, keyed
     * `{user id}:{session unit id}:{unit_hierarchy version}`.
     *
     * One `/dashboard` render resolves the access array 7 times (mount + every
     * scope-key accessor + the closures they guard), and each resolution costs
     * the `unit_hierarchy_version` lookup, the `accessible_units:` lookup and —
     * on a miss — the same two again inside `Unit::descendantIds()`. The array
     * cannot change between those calls unless the hierarchy itself changes, so
     * it is resolved once and every later call is a single version read plus a
     * memo hit.
     *
     * The version is part of the key on purpose: `Unit::created/updated/deleted`
     * bump `unit_hierarchy` from `AppServiceProvider` (and `clearCache()` /
     * `clearAllCaches()` bump it directly), so a request that writes a unit must
     * not keep serving the scope it read before the write — the same guarantee
     * `HardwareGisCacheTest` pins for the GIS cache. Keying on the version makes
     * that structural instead of relying on every bump site to remember the
     * memo. The key also carries the user and the session unit, so the memo
     * cannot leak between two users or across a context switch.
     *
     * This only pays off because the container hands out ONE instance per
     * request — see the `scoped()` binding in `AppServiceProvider`. The class is
     * stateless, so a singleton binding on its own would change nothing.
     *
     * @var array<string, array<int>>
     */
    private array $memo = [];

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

        $sessionUnitId = session('current_unit_id', 'none');
        $cache = app(CacheInvalidationServiceInterface::class);
        // Read once and reuse below: the memo key needs the current hierarchy
        // version, and the `accessible_units:` key is built from the same value.
        $version = $cache->getVersion('unit_hierarchy');
        $memoKey = $user->id.':'.$sessionUnitId.':'.$version;

        // array_key_exists, not isset: `[]` is a legitimate answer (an account
        // with no unit is "in scope of nothing") and must be memoized too.
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
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
            return $this->memo[$memoKey] = [];
        }

        $sortedBase = $baseUnitIds;
        sort($sortedBase);
        $cacheKey = "accessible_units:v{$version}:{$user->id}:{$sessionUnitId}:".md5(json_encode($sortedBase));

        $ids = Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            fn () => Unit::descendantIds($baseUnitIds)->toArray()
        );

        return $this->memo[$memoKey] = $ids;
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
        // Drop the request-level memo first: the version bump below must be
        // visible to every later call in THIS request, not just to the next one.
        $this->memo = [];

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
        // Same reason as clearCache(): a hierarchy write inside one request must
        // be visible to the reads that follow it in that request.
        $this->memo = [];

        $cache = app(CacheInvalidationServiceInterface::class);
        $cache->increment('unit_hierarchy');
        $cache->increment('gis');
    }
}
