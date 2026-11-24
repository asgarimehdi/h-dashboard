<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #1767 — harden the public CSP reporting endpoint.
 *
 * Browsers reach this route through the Reporting API (`Reporting-Endpoints`
 * + `report-to csp-endpoint` in SecurityHeaders), so the real body is
 * `application/reports+json`: a JSON ARRAY of `csp-violation` envelopes.
 * The legacy `report-uri` shape (`application/csp-report`,
 * `{"csp-report": {…}}`) is still accepted for compatibility even though the
 * policy no longer emits `report-uri`.
 *
 * The route is unauthenticated by design (browsers cannot attach credentials
 * to violation reports), so the defence is shape + size validation, not auth.
 * Every caller gets 204; junk is dropped without a log line.
 */
class CspReportController extends Controller
{
    private const MAX_BODY_BYTES = 16384;   // 16 KiB raw body

    private const MAX_REPORTS = 20;         // Reporting API array entries

    private const MAX_FIELDS = 20;          // keys per report object

    private const MAX_STRING_BYTES = 4096;  // per string value

    private const MAX_CONTENT_TYPE = 100;   // logged content-type length

    private const LEGACY_FIELDS = [
        'document-uri', 'referrer', 'violated-directive', 'effective-directive',
        'original-policy', 'disposition', 'blocked-uri', 'line-number',
        'column-number', 'source-file', 'status-code', 'script-sample',
    ];

    private const LEGACY_INT_FIELDS = ['line-number', 'column-number', 'status-code'];

    private const REPORTING_BODY_FIELDS = [
        'documentURL', 'referrer', 'blockedURL', 'effectiveDirective',
        'originalPolicy', 'disposition', 'sourceFile', 'sample',
        'statusCode', 'lineNumber', 'columnNumber',
    ];

    private const REPORTING_INT_FIELDS = ['statusCode', 'lineNumber', 'columnNumber'];

    public function __invoke(Request $request): Response
    {
        // Cheap pre-check: do not read an honest oversized body at all.
        $contentLength = (int) ($request->headers->get('Content-Length') ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            return response()->noContent();
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->noContent();
        }

        // Decode ourselves: json_decode() is typed mixed, so array_is_list()
        // below is a real runtime distinction. $request->json()->all() is
        // stubbed as a string-keyed array, which hides that check from static
        // analysis even though a Reporting API body is a JSON list.
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->noContent();
        }

        if (array_is_list($payload)) {
            $reports = $this->validReportingReports($payload);
            if ($reports !== []) {
                Log::warning('csp-report', [
                    'content_type' => $this->contentType($request),
                    'shape' => 'reporting-api',
                    'reports' => $reports,
                ]);
            }

            return response()->noContent();
        }

        $legacy = $this->validLegacyReport($payload);
        if ($legacy !== null) {
            Log::warning('csp-report', [
                'content_type' => $this->contentType($request),
                'shape' => 'legacy',
                'report' => ['csp-report' => $legacy],
            ]);
        }

        return response()->noContent();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function validLegacyReport(array $payload): ?array
    {
        if (array_keys($payload) !== ['csp-report']) {
            return null;
        }

        $inner = $payload['csp-report'];
        if (! is_array($inner) || $inner === [] || array_is_list($inner)) {
            return null;
        }

        if (count($inner) > self::MAX_FIELDS) {
            return null;
        }

        $filtered = $this->filterFields($inner, self::LEGACY_FIELDS, self::LEGACY_INT_FIELDS);

        return $filtered === [] ? null : $filtered;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function validReportingReports(array $payload): array
    {
        if ($payload === [] || count($payload) > self::MAX_REPORTS) {
            return [];
        }

        /** @var list<array<string, mixed>> $reports */
        $reports = [];

        foreach ($payload as $entry) {
            if (! is_array($entry) || $entry === [] || array_is_list($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) !== 'csp-violation') {
                continue;
            }

            $body = $entry['body'] ?? null;
            if (! is_array($body) || $body === [] || array_is_list($body)) {
                continue;
            }

            if (count($entry) > self::MAX_FIELDS || count($body) > self::MAX_FIELDS) {
                continue;
            }

            $filteredBody = $this->filterFields($body, self::REPORTING_BODY_FIELDS, self::REPORTING_INT_FIELDS);
            if ($filteredBody === []) {
                continue;
            }

            /** @var array<string, mixed> $report */
            $report = ['type' => 'csp-violation'];

            $age = $entry['age'] ?? null;
            if (is_int($age)) {
                $report['age'] = $age;
            } elseif (is_string($age) && ctype_digit($age) && strlen($age) <= 20) {
                $report['age'] = (int) $age;
            }

            foreach (['url', 'user_agent'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && strlen($value) <= self::MAX_STRING_BYTES) {
                    $report[$key] = $this->sanitize($value);
                }
            }

            $report['body'] = $filteredBody;
            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * Preserve input order so the existing `===` assertion in the legacy test
     * keeps working; unknown keys are dropped, never logged.
     *
     * @param  array<array-key, mixed>  $fields
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $intFields
     * @return array<string, mixed>
     */
    private function filterFields(array $fields, array $allowed, array $intFields): array
    {
        $filtered = [];

        foreach ($fields as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }

            if ($value === null) {
                $filtered[$key] = null;

                continue;
            }

            if (in_array($key, $intFields, true)) {
                if (is_int($value)) {
                    $filtered[$key] = $value;
                } elseif (is_string($value) && ctype_digit($value) && strlen($value) <= 20) {
                    $filtered[$key] = (int) $value;
                }

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            // One oversized string drops the whole report (the caller returns
            // null/[]), keeping every logged value bounded.
            if (strlen($value) > self::MAX_STRING_BYTES) {
                return [];
            }

            $filtered[$key] = $this->sanitize($value);
        }

        return $filtered;
    }

    private function contentType(Request $request): string
    {
        return substr(
            $this->sanitize($request->headers->get('Content-Type') ?? ''),
            0,
            self::MAX_CONTENT_TYPE,
        );
    }

    /**
     * Laravel's default LineFormatter runs with allowInlineLineBreaks=true
     * (LogManager::formatter) and Monolog turns JSON-escaped `\n`/`\r` back
     * into real newlines — a forged log line otherwise. Strip control
     * characters (and the Unicode line separators) before the log context.
     */
    private function sanitize(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', ' ', $value) ?? '';
    }
}
