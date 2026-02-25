<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ZabbixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MultiLatestValueController extends Controller
{
    public function index(Request $request, ZabbixService $zabbix): JsonResponse
    {
        $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'required|string'
        ]);

        $itemIds = $request->item_ids;
        sort($itemIds); // مرتب‌سازی برای یکسان بودن کلید کش

        $cacheKey = 'multi_latest_' . implode('_', $itemIds);

        try {
            $values = Cache::remember($cacheKey, 20, function () use ($zabbix, $itemIds) {
                return $zabbix->getLatestValues($itemIds);
            });

            return response()->json($values);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Zabbix connection failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}