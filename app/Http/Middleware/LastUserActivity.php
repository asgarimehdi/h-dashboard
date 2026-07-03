<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class LastUserActivity
{
    /**
     * The cache key prefix for user activity
     */
    const CACHE_KEY_PREFIX = 'user:activity:';

    /**
     * The duration in minutes to consider user as online
     */
    const ONLINE_DURATION = 3;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $this->updateLastActivity(Auth::id());
        }

        return $next($request);
    }

    /**
     * Update the user's last activity timestamp in cache
     */
    protected function updateLastActivity(int $userId): void
    {
        Cache::put(
            self::CACHE_KEY_PREFIX . $userId,
            now()->toDateTimeString(),
            now()->addMinutes(self::ONLINE_DURATION)
        );
    }

    /**
     * Check if a user is currently online
     */
    public static function isOnline(int $userId): bool
    {
        return Cache::has(self::CACHE_KEY_PREFIX . $userId);
    }

    /**
     * Get the user's last activity timestamp
     */
    public static function getLastActivity(int $userId): ?string
    {
        return Cache::get(self::CACHE_KEY_PREFIX . $userId);
    }
}