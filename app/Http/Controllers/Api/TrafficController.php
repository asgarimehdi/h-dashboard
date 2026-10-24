<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zabbix\ZabbixClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TrafficController extends Controller
{
    public function index(Request $request, ZabbixClient $zabbix)
    {
        // #1019: both item ids feed the cache key, so an arbitrary string can
        // mint unbounded keys (each one forcing fresh upstream calls). Real
        // Zabbix item ids are numeric strings (nonNumeric=0 across the five
        // zabbix_devices id columns), so constrain them here — validation runs
        // before any cache, lock or upstream access.
        $validated = $request->validate([
            'out_item_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'],
            'in_item_id' => ['required', 'string', 'max:32', 'regex:/^\d+$/'],
            'duration' => 'nullable|integer|min:60|max:86400',
        ]);

        $outItemId = $validated['out_item_id'];
        $inItemId = $validated['in_item_id'];
        $duration = $validated['duration'] ?? 3600;

        try {
            // Key format is frozen (#764, #1019): traffic_<out>_<in>_<duration>.
            $cacheKey = "traffic_{$outItemId}_{$inItemId}_{$duration}";

            // #764: read the per-request cache back — it used to be a
            // write-only key while EVERY request was short-circuited by the
            // sync job's `zabbix_traffic_data` (default config items, wrong
            // `['data' => ...]` shape) regardless of the requested item IDs.
            // The job's cache stays for the dashboard banner (`Cache::has`),
            // but the controller must not serve another request's data.
            //
            // #1019: fresh window 240s inside a 300s total TTL — shorter
            // upstream intervals trip the Zabbix rate limit (the 5-minute
            // staleness accepted for both widgets), while a request that
            // arrives during an in-flight refresh is served the stale value
            // instead of erroring: no busy 503s, no error-level logs for
            // contention, and the refresh lock is released even when the
            // upstream fails.
            $data = Cache::flexible($cacheKey, [240, 300], function () use ($zabbix, $outItemId, $inItemId, $duration) {
                // #741: the transport now returns a typed result instead of
                // throwing; the mapping below keeps the unchanged 503 contract
                // (same body through the catch). A failed result is never
                // cached: the compute throws, so flexible's write is skipped
                // and the previous (stale) value survives for the next
                // request.
                $out = $zabbix->traffic($outItemId, $duration);

                if ($out->failed()) {
                    throw new RuntimeException(
                        "Zabbix traffic API error (out {$out->failure()}): {$out->message()}"
                    );
                }

                $in = $zabbix->traffic($inItemId, $duration);

                if ($in->failed()) {
                    throw new RuntimeException(
                        "Zabbix traffic API error (in {$in->failure()}): {$in->message()}"
                    );
                }

                return [
                    'out' => $out->data(),
                    'in' => $in->data(),
                ];
            });

            return response()->json($data);
        } catch (Throwable $e) {
            Log::error('Zabbix Traffic API error', ['exception' => $e]);

            return response()->json(['error' => 'Service temporarily unavailable'], 503);
        }
    }
}
