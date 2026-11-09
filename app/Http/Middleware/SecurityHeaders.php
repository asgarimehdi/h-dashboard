<?php

namespace App\Http\Middleware;

use App\Support\CspPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // #1281: per-request nonce. Generated BEFORE the response so every
        // blade tag (and the Vite-injected ones) rendered inside $next can
        // read it back through Vite::cspNonce().
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // CSP: report-only unless CSP_ENFORCING is true (config, not env — the
        // cached-config env() gotcha would otherwise flip it off in production).
        // Reporting-Endpoints stays in both modes; the destination stays the
        // same-app /csp-report endpoint (#742 / issue #1281 decision d).
        $csp = CspPolicy::for($nonce);
        if ((bool) config('csp.enforcing', false)) {
            $response->headers->set('Content-Security-Policy', $csp);
        } else {
            $response->headers->set('Content-Security-Policy-Report-Only', $csp);
        }
        $response->headers->set('Reporting-Endpoints', 'csp-endpoint="/csp-report"');

        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
