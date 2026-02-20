<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ZabbixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TrafficController extends Controller
{
  public function index(Request $request, ZabbixService $zabbix)
{
    $validated = $request->validate([
        'out_item_id' => 'required',
        'in_item_id'  => 'required',
        'duration'    => 'nullable|integer|min:60|max:86400'
    ]);

    $outItemId = $validated['out_item_id'];
    $inItemId  = $validated['in_item_id'];
    $duration  = $validated['duration'] ?? 3600;

    // Cache key now includes both IDs
    $data = Cache::remember(
        "traffic_{$outItemId}_{$inItemId}_{$duration}",
        30,
        function () use ($zabbix, $outItemId, $inItemId, $duration) {
            return [
                'out' => $zabbix->getInterfaceTraffic($outItemId, $duration),
                'in'  => $zabbix->getInterfaceTraffic($inItemId, $duration),
            ];
        }
    );

    return response()->json($data);
}
}