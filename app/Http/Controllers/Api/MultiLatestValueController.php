<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ZabbixDevice;
use App\Services\Zabbix\ZabbixClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class MultiLatestValueController extends Controller
{
    public function index(Request $request, ZabbixClient $zabbix): JsonResponse
    {
        // #1019: item ids feed the cache key (`multi_latest_<sorted ids>`), so
        // they must be numeric strings — validation runs before any cache,
        // lock or upstream access. Real Zabbix item ids are numeric
        // (nonNumeric=0 across the five zabbix_devices id columns).
        //
        // #1621: every id must belong to an active registered device, and the
        // whole request is rejected if ANY id is unregistered (fail-closed, no
        // partial result). The check is inside validate() so the cache key is
        // never built for an unregistered id; `Rule::in([])` rejects all, so an
        // empty registry is fail-closed.
        $allowedItemIds = ZabbixDevice::registeredItemIds();

        $validated = $request->validate([
            'item_ids' => 'required|array|max:100',
            'item_ids.*' => ['required', 'string', 'max:64', 'regex:/^\d+$/', Rule::in($allowedItemIds)],
        ]);

        $itemIds = $validated['item_ids'];
        sort($itemIds); // مرتب‌سازی برای یکسان بودن کلید کش (فرمت کلید مطابق #1019 یخ‌زده است)

        // Key format is frozen (#1019): multi_latest_<ids sorted>.
        $cacheKey = 'multi_latest_'.implode('_', $itemIds);

        try {
            // #1019: fresh window 240s inside a 300s total TTL to protect the
            // Zabbix upstream (rate limit); a request arriving during an
            // in-flight refresh is served the stale value instead of erroring.
            $values = Cache::flexible($cacheKey, [240, 300], function () use ($zabbix, $itemIds) {
                // #741: typed transport result. A failure is NOT written to the
                // cache — remember() never cached exceptions either, so a broken
                // Zabbix must not pin its own error for the TTL. The compute
                // throws so flexible's write is skipped and the mapping below
                // keeps the same 503 body through the catch.
                $result = $zabbix->latestValues($itemIds);

                if ($result->failed()) {
                    throw new RuntimeException(
                        "Zabbix API error ({$result->failure()}): {$result->message()}"
                    );
                }

                return $result->data();
            });

            return response()->json($values);
        } catch (Throwable $e) {
            Log::error('Zabbix API error', ['exception' => $e]);

            return response()->json(['error' => 'Service temporarily unavailable'], 503);
        }
    }
}
