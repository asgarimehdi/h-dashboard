<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ZabbixService
{
    /**
     * حداکثر ردیف خام history که برای هر item در هر درخواست کشیده می‌شود.
     *
     * بزرگ‌ترین پنجره‌ی قابل انتخاب ۲۴ ساعت است (TrafficController:34).
     * با interval ۳۰s این ۲٬۸۸۰ ردیف و با ۶۰s فقط ۱٬۴۴۰ ردیف است؛ سقف ۵٬۰۰۰
     * کل پنجره را برای هر interval ≥ ۱۷.۳s پوشش می‌دهد و در intervalهای
     * غیرعادی (مثلاً ۱s ⇒ ۸۶٬۴۰۰ ردیف) هزینه را به «جدیدترین برش پیوسته»
     * محدود می‌کند.
     */
    private const TRAFFIC_MAX_SAMPLES = 5000;

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

        $response = $this->request('history.get', [
            'output' => ['clock', 'value'], // itemid/ns استفاده نمی‌شوند
            'history' => 3,
            'itemids' => $itemId,
            'sortfield' => 'clock',
            'sortorder' => 'DESC', // تا limit جدیدترین‌ها را نگه دارد
            'limit' => self::TRAFFIC_MAX_SAMPLES,
            'time_from' => $timeFrom,
            'time_till' => $now,
        ]);

        if (! isset($response['result'])) {
            return [];
        }

        // DESC برگردانده می‌شود؛ به ASC برمی‌گردانیم تا حلقه‌ی diff و ترتیب x
        // دقیقاً مثل قبل بماند. (Zabbix فقط در DESC سقف را با chunking زمانی
        // اعمال می‌کند — CHistory::get.)
        $history = collect($response['result'])->reverse()->values();
        $points = [];

        for ($i = 1; $i < $history->count(); $i++) {
            $prev = $history[$i - 1];
            $curr = $history[$i];

            $timeDiff = $curr['clock'] - $prev['clock'];
            $valueDiff = $curr['value'] - $prev['value'];
            $bps = ($timeDiff > 0 && $valueDiff >= 0) ? ($valueDiff / $timeDiff) : 0;

            $points[] = [
                'x' => $curr['clock'] * 1000,
                'y' => round($bps / 1000000, 2),
            ];
        }

        return $points;
    }

    public function getItemIdByKey($key)
    {
        $response = $this->request('item.get', [
            'output' => ['itemid'],
            'filter' => [
                'key_' => $key,
            ],
        ]);

        if (! isset($response['result'][0])) {
            return null;
        }

        return $response['result'][0]['itemid'] ?? null;
    }

    protected function request($method, $params = [])
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->auth,
        ])->timeout(10)->post($this->url, [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        if ($response->failed()) {
            throw new \Exception('Zabbix API HTTP error: '.$response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \Exception('Zabbix API returned invalid JSON');
        }

        if (isset($data['error'])) {
            throw new \Exception('Zabbix API error: '.($data['error']['message'] ?? 'unknown'));
        }

        return $data;
    }

    /**
     * تست اتصال واقعی به Zabbix API — version info بازمی‌گردونه.
     *
     * @return array{version: string}
     *
     * @throws \Exception
     */
    public function testApiConnection(): array
    {
        $response = $this->request('apiinfo.version', []);

        $version = $response['result'] ?? null;

        if (! is_string($version)) {
            throw new \Exception('Zabbix API returned unexpected version response');
        }

        return ['version' => $version];
    }

    public function getLatestValues(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $response = $this->request('item.get', [
            'output' => ['itemid', 'lastvalue'],
            'itemids' => $itemIds,
        ]);

        $result = [];
        foreach ($response['result'] as $item) {
            $result[$item['itemid']] = isset($item['lastvalue']) ? (float) $item['lastvalue'] : null;
        }

        // اطمینان از وجود کلید برای همه آیتم‌های درخواستی
        foreach ($itemIds as $id) {
            if (! array_key_exists($id, $result)) {
                $result[$id] = null;
            }
        }

        return $result;
    }
}
