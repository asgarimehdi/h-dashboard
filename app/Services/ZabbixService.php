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
protected function request($method, $params = [])
{
    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $this->auth,
    ])->post($this->url, [
        "jsonrpc" => "2.0",
        "method"  => $method,
        "params"  => $params,
        "id"      => 1
    ]);

    if ($response->failed()) {
        throw new \Exception("Zabbix API HTTP error: " . $response->status());
    }

    $data = $response->json();
    if (!is_array($data)) {
        throw new \Exception("Zabbix API returned invalid JSON");
    }

    if (isset($data['error'])) {
        throw new \Exception("Zabbix API error: " . ($data['error']['message'] ?? 'unknown'));
    }

    return $data;
}
public function getLatestValues(array $itemIds): array
{
    if (empty($itemIds)) {
        return [];
    }

    $response = $this->request("item.get", [
        "output" => ["itemid", "lastvalue"],
        "itemids" => $itemIds
    ]);

    $result = [];
    foreach ($response['result'] as $item) {
        $result[$item['itemid']] = isset($item['lastvalue']) ? (float) $item['lastvalue'] : null;
    }

    // اطمینان از وجود کلید برای همه آیتم‌های درخواستی
    foreach ($itemIds as $id) {
        if (!array_key_exists($id, $result)) {
            $result[$id] = null;
        }
    }

    return $result;
}

}