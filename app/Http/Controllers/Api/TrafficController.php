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
        'interface' => 'required'
    ]);

    $interface = $validated['interface'];

    $data = Cache::remember(
        "traffic_{$interface}",
        20,
        function () use ($zabbix, $interface) {

            $outItemId = $zabbix->getItemIdByKey("net.if.out[{$interface}]");
            $inItemId  = $zabbix->getItemIdByKey("net.if.in[{$interface}]");

            return [
                'out' => $zabbix->getInterfaceTraffic($outItemId),
                'in'  => $zabbix->getInterfaceTraffic($inItemId),
            ];
        }
    );

    return response()->json($data);
}

}
