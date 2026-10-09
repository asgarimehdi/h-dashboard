<?php

use App\Http\Middleware\LastUserActivity;
use App\Http\Middleware\SafeRoleOrPermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateUnitContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
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
        // Browser-submitted CSP reports arrive without a CSRF token (#742).
        $middleware->validateCsrfTokens(except: ['csp-report']);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'safe_role_or_permission' => SafeRoleOrPermission::class,
            'unit_context' => ValidateUnitContext::class,
            'last.activity' => LastUserActivity::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
        $middleware->web(append: [
            SecurityHeaders::class,
            LastUserActivity::class,
        ]);

        // Trust proxies for HTTPS detection behind Cloudflare/load balancer.
        //
        // Issue #855: this defaulted to '*', which TrustProxies expands to
        // setTrustedProxies(['0.0.0.0/0', '::/0'], …) — every address on the
        // internet became a trusted proxy, so a client-supplied
        // X-Forwarded-Host/-Proto/-Port/-Prefix became the root of every
        // generated absolute URL (notification rows, paginator `links`,
        // asset(), and the guest redirect all inherit it).
        //
        // The default is now null — trust nothing — which is correct for the
        // documented direct nginx+FPM deployment. A deployment that DOES sit
        // behind a proxy must pin its ranges explicitly in TRUSTED_PROXIES,
        // then run `config:clear` / `optimize`: a cached config freezes the
        // value read here at boot (LoadEnvironmentVariables returns early when
        // config is cached), so editing .env alone would not take effect.
        //
        // X-Forwarded-For is deliberately NOT in the bitmask (#321): with an
        // unbounded trust list a client could forge its own IP, poisoning both
        // the throttle key and the audit-log IP column. That decision only
        // holds once the proxy list is pinned — reconciled in PR #878's
        // sibling (#855) body.
        $trustedProxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PREFIX
        );

        // Backstop that makes a wrong TRUSTED_PROXIES fail closed: with a host
        // allowlist, Request::getHost() throws on a host outside it instead of
        // silently adopting the client's value. Left empty, the allowlist
        // falls back to APP_URL — so a real deployment must set APP_URL (or
        // TRUSTED_HOSTS) to the hostname it actually serves.
        $middleware->trustHosts(at: array_filter(explode(',', (string) env('TRUSTED_HOSTS', ''))));
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

        // Register Sentry exception handler — captures unhandled exceptions and sends them to Sentry
        Integration::handles($exceptions);
    })->create();
