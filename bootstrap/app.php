<?php

use App\Http\Middleware\LastUserActivity;
use App\Http\Middleware\SafeRoleOrPermission;
use App\Http\Middleware\ValidateUnitContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'safe_role_or_permission' => SafeRoleOrPermission::class,
            'unit_context' => ValidateUnitContext::class,
            'last.activity' => LastUserActivity::class,
        ]);
        $middleware->web(append: [
            LastUserActivity::class,
        ]);

        // Trust proxies for HTTPS detection behind Cloudflare/load balancer
        // Only trust X-Forwarded-Proto/Host for HTTPS detection — NOT X-Forwarded-For
        // to prevent IP spoofing via fake X-Forwarded-For headers (Issue #321)
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PREFIX
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Custom handling for NotFoundHttpException to return clean 404 responses
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // In debug mode, return the full exception details
                if (config('app.debug')) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                    ], 404);
                }

                // In production, return a clean 404 message
                return response()->json([
                    'message' => 'Not Found',
                ], 404);
            }
        });

        // Custom handling for ModelNotFoundException (route model binding 404s)
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // In debug mode, return the full exception details
                if (config('app.debug')) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                    ], 404);
                }

                // In production, return a clean 404 message
                return response()->json([
                    'message' => 'Not Found',
                ], 404);
            }
        });
    })->create();
