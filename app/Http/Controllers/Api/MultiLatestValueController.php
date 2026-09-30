<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zabbix\ZabbixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MultiLatestValueController extends Controller
{
    public function index(Request $request, ZabbixClient $zabbix): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array|max:100',
            'item_ids.*' => 'required|string|max:64',
        ]);

        $itemIds = $request->item_ids;
        sort($itemIds); // مرتب‌سازی برای یکسان بودن کلید کش

        $cacheKey = 'multi_latest_'.implode('_', $itemIds);

        try {
            $values = Cache::get($cacheKey);

            if ($values !== null) {
                return response()->json($values);
            }

            // #741: typed transport result. A failure is NOT written to the
            // cache — remember() never cached exceptions either, so a broken
            // Zabbix must not pin its own error for the TTL.
            $result = $zabbix->latestValues($itemIds);

            if ($result->failed()) {
                Log::error('Zabbix API error', [
                    'failure' => $result->failure(),
                    'message' => $result->message(),
                ]);

                return response()->json(['error' => 'Service temporarily unavailable'], 503);
            }

            $values = $result->data();
            Cache::put($cacheKey, $values, 20);

            return response()->json($values);
        } catch (Throwable $e) {
            Log::error('Zabbix API error', ['exception' => $e]);

            return response()->json(['error' => 'Service temporarily unavailable'], 503);
        }
    }
}
