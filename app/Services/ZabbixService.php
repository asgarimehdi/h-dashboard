<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ZabbixService
{
    protected string $url;
    protected string $auth;

    public function __construct()
    {
        $this->url = config('services.zabbix.url');
        $this->auth = config('services.zabbix.token');
    }

    protected function request($method, $params = [])
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->auth,
        ])->post($this->url, [
            "jsonrpc" => "2.0",
            "method"  => $method,
            "params"  => $params,
            "id"      => 1
        ])->json();
    }

    public function getInterfaceTraffic($itemId, $duration = 3600) // <-- پارامتر جدید
    {
        $now = time();
        $timeFrom = $now - $duration; // استفاده از duration

        $response = $this->request("history.get", [
            "output" => "extend",
            "history" => 3,
            "itemids" => $itemId,
            "sortfield" => "clock",
            "sortorder" => "ASC",
            "time_from" => $timeFrom,
            "time_till" => $now,
        ]);

        if (!isset($response['result'])) {
            return [];
        }

        $history = collect($response['result']);
        $points = [];

        for ($i = 1; $i < $history->count(); $i++) {
            $prev = $history[$i - 1];
            $curr = $history[$i];

            $timeDiff = $curr['clock'] - $prev['clock'];
            $bps = $timeDiff > 0 ? ($curr['value']) : 0;

            $points[] = [
                'x' => $curr['clock'] * 1000,
                'y' => round($bps / 1000000, 2)
            ];
        }

        return $points;
    }

    public function getItemIdByKey($key)
    {
        $response = $this->request("item.get", [
            "output" => ["itemid"],
            "filter" => [
                "key_" => $key
            ]
        ]);

        return $response['result'][0]['itemid'] ?? null;
    }
}