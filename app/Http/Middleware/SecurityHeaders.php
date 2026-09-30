<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // CSP in report-only mode first — validate for 1-2 weeks before enforcing.
        // `report-uri` is deprecated and ignored by modern Chrome, so it is gone:
        // violations go through the Reporting API (`report-to` directive plus
        // this Reporting-Endpoints header) to POST /csp-report (#742). The
        // same-app destination is temporary — the final target is a
        // separate-domain endpoint (decision recorded on issue #742).
        $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; report-to csp-endpoint");
        $response->headers->set('Reporting-Endpoints', 'csp-endpoint="/csp-report"');

        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
