<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

/**
 * Safe version of role_or_permission: skips the permission check for unauthenticated guests.
 * This allows routes to work without auth while still requiring permissions when the user is logged in.
 */
class SafeRoleOrPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        if (! auth()->check()) {
            return $next($request);
        }

        return app(RoleOrPermissionMiddleware::class)->handle($request, $next, ...$permissions);
    }
}