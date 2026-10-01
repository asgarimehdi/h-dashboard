<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zabbix\ZabbixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrafficController extends Controller
{
    public function index(Request $request, ZabbixClient $zabbix)
    {
        $validated = $request->validate([
            'out_item_id' => 'required',
            'in_item_id' => 'required',
            'duration' => 'nullable|integer|min:60|max:86400',
        ]);

        $outItemId = $validated['out_item_id'];
        $inItemId = $validated['in_item_id'];
        $duration = $validated['duration'] ?? 3600;

        try {
            $cacheKey = "traffic_{$outItemId}_{$inItemId}_{$duration}";

            // #764: read the per-request cache back — it used to be a
            // write-only key while EVERY request was short-circuited by the
            // sync job's `zabbix_traffic_data` (default config items, wrong
            // `['data' => ...]` shape) regardless of the requested item IDs.
            // The job's cache stays for the dashboard banner (`Cache::has`),
            // but the controller must not serve another request's data.
            $data = Cache::get($cacheKey);

            if ($data === null) {
                // #741: the transport now returns a typed result instead of
                // throwing; the mapping below (connection problem -> 503 with the
                // exact same body) is the contract the existing tests pin.
                $out = $zabbix->traffic($outItemId, $duration);

                if ($out->failed()) {
                    return $this->unavailable('out', $out->failure(), $out->message());
                }

                $in = $zabbix->traffic($inItemId, $duration);

                if ($in->failed()) {
                    return $this->unavailable('in', $in->failure(), $in->message());
                }

                $data = [
                    'out' => $out->data(),
                    'in' => $in->data(),
                ];

                Cache::put($cacheKey, $data, 30);
            }

            return response()->json($data);
        } catch (Throwable $e) {
            Log::error('Zabbix Traffic API error', ['exception' => $e]);

            return response()->json(['error' => 'Service temporarily unavailable'], 503);
        }
    }

    /**
     * The unchanged 503 contract, reached through a typed failure (#741):
     * same body, same status, only the log now carries the failure kind.
     */
    protected function unavailable(string $side, ?string $failure, ?string $message): JsonResponse
    {
        Log::error('Zabbix Traffic API error', [
            'side' => $side,
            'failure' => $failure,
            'message' => $message,
        ]);

        return response()->json(['error' => 'Service temporarily unavailable'], 503);
    }
}
