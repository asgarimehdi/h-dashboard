<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'safe_role_or_permission' => \App\Http\Middleware\SafeRoleOrPermission::class,
            'unit_context' => \App\Http\Middleware\ValidateUnitContext::class,
            'last.activity' => \App\Http\Middleware\LastUserActivity::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\LastUserActivity::class,
        ]);
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