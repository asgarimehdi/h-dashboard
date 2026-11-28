<?php

namespace Tests\Unit;

use App\Services\ZabbixService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

covers(ZabbixService::class);

class ZabbixServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock the config values
        config(['services.zabbix.url' => 'http://zabbix.local/api_json_rpc.php', 'services.zabbix.token' => 'test-token']);
    }

    public function test_request_sends_correct_auth_header()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => ['token' => 'test-token'],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $service->getItemIdByKey('test.key');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_get_interface_traffic_calculates_rate_correctly()
    {
        // Zabbix returns newest first because the service asks for DESC.
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => [
                    ['value' => 5000000, 'clock' => 1600000060], // 4M diff, 60s diff
                    ['value' => 1000000, 'clock' => 1600000000],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $result = $service->getInterfaceTraffic('item-123');

        // Rate = (5M - 1M) / (60s) / 1e6 = 4/60 = 0.066...
        $this->assertCount(1, $result);
        $this->assertEquals(0.07, $result[0]['y']); // rounded to 2 decimals
        $this->assertEquals(1600000060000, $result[0]['x']); // DESC response restored to ASC
    }

    public function test_get_interface_traffic_bounds_the_history_window()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => [
                    ['value' => 5000000, 'clock' => 1600000060],
                    ['value' => 1000000, 'clock' => 1600000000],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $service->getInterfaceTraffic('item-123', 86400);

        Http::assertSent(function ($request) {
            $data = $request->data();

            if (($data['method'] ?? null) !== 'history.get') {
                return false;
            }

            $params = $data['params'];

            return $params['limit'] === 5000
                && $params['sortorder'] === 'DESC'
                && $params['output'] === ['clock', 'value']
                && $params['history'] === 3
                && $params['sortfield'] === 'clock'
                && $params['time_till'] - $params['time_from'] === 86400;
        });
    }

    public function test_get_interface_traffic_returns_empty_on_single_sample()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => [
                    ['value' => 1000000, 'clock' => 1600000000],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $result = $service->getInterfaceTraffic('item-123');

        $this->assertEmpty($result);
    }

    public function test_get_latest_values_fills_nulls_for_missing_ids()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => [
                    ['itemid' => '1', 'lastvalue' => '10.5'],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $result = $service->getLatestValues(['1', '2']);

        $this->assertEquals(10.5, $result['1']);
        $this->assertNull($result['2']);
    }

    public function test_request_throws_exception_on_zabbix_error()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'result' => null,
                'error' => ['code' => -32602, 'message' => 'Invalid params'],
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid params');

        $service->getItemIdByKey('test.key');
    }

    public function test_test_api_connection_returns_version()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'jsonrpc' => '2.0',
                'result' => '6.0.0',
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $result = $service->testApiConnection();

        $this->assertEquals(['version' => '6.0.0'], $result);
    }

    public function test_test_api_connection_throws_on_http_error()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response('', 500),
        ]);

        $service = new ZabbixService;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Zabbix API HTTP error');

        $service->testApiConnection();
    }

    public function test_test_api_connection_throws_on_invalid_response()
    {
        Http::fake([
            'http://zabbix.local/api_json_rpc.php' => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['unexpected' => 'format'],
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('unexpected version response');

        $service->testApiConnection();
    }
}
