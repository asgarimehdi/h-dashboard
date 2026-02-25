<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ZabbixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        try {
            $values = $zabbix->getLatestValues($itemIds);
            return response()->json($values);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Zabbix connection failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}