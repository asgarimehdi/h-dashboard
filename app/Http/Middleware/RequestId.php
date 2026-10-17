<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #1068: give every request a correlation id.
 *
 * The id is put into Laravel's `Context`, so `ContextLogProcessor` adds it to
 * the `extra` of every log record — no `config/logging.php` change needed — and
 * `ContextServiceProvider` carries it across the queue, so a job that writes
 * the failing row logs under the same id as the HTTP request that dispatched
 * it. It is also echoed on the `X-Request-Id` response header so a user can
 * quote it in a support report.
 *
 * The id is always self-generated: an inbound `X-Request-Id` is ignored, so a
 * caller cannot dictate what lands in our logs (log-injection guard).
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
