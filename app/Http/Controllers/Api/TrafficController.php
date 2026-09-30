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
            $cached = Cache::get('zabbix_traffic_data');

            if ($cached !== null) {
                return response()->json(['data' => $cached]);
            }

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

            Cache::put("traffic_{$outItemId}_{$inItemId}_{$duration}", $data, 30);

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
