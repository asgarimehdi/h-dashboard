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
            'interface' => 'required',
            'duration'  => 'nullable|integer|min:60|max:86400' // بین ۱ دقیقه تا ۲۴ ساعت
        ]);

        $interface = $validated['interface'];
        $duration = $validated['duration'] ?? 3600; // پیش‌فرض ۱ ساعت

        // کلید کش شامل duration نیز می‌شود
        $data = Cache::remember(
            "traffic_{$interface}_{$duration}",
            20,
            function () use ($zabbix, $interface, $duration) {

                $outItemId = $zabbix->getItemIdByKey("net.if.out[{$interface}]");
                $inItemId  = $zabbix->getItemIdByKey("net.if.in[{$interface}]");

                return [
                    'out' => $zabbix->getInterfaceTraffic($outItemId, $duration),
                    'in'  => $zabbix->getInterfaceTraffic($inItemId, $duration),
                ];
            }
        );

        return response()->json($data);
    }
}