<?php

namespace Tests\Unit;

use App\Services\ZabbixService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZabbixServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock the config values
        config(['services.zabbix.url' => 'http://zabbix.local', 'services.zabbix.token' => 'test-token']);
    }

    public function test_request_sends_correct_auth_header()
    {
        Http::fake();

        $service = new ZabbixService();
        $service->request(['jsonrpc' => '2.0', 'method' => 'user.login', 'params' => [], 'id' => 1]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_get_interface_traffic_calculates_rate_correctly()
    {
        Http::fake([
            '*/api_json_rpc.php*' => Http::response([
                'result' => [
                    ['value' => 1000000, 'clock' => 1600000000],
                    ['value' => 5000000, 'clock' => 1600000060], // 4s diff, 4M diff
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService();
        $result = $service->getInterfaceTraffic('item-123');

        // Rate = (5M - 1M) / (60s) / 1e6 = 4/60 = 0.066...
        $this->assertCount(1, $result);
        $this->assertEquals(0.07, $result[0]['y']); // rounded to 2 decimals
    }

    public function test_get_interface_traffic_returns_empty_on_single_sample()
    {
        Http::fake([
            '*/api_json_rpc.php*' => Http::response([
                'result' => [
                    ['value' => 1000000, 'clock' => 1600000000],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService();
        $result = $service->getInterfaceTraffic('item-123');

        $this->assertEmpty($result);
    }

    public function test_get_latest_values_fills_nulls_for_missing_ids()
    {
        Http::fake([
            '*/api_json_rpc.php*' => Http::response([
                'result' => [
                    ['itemid' => '1', 'value' => '10.5'],
                ],
                'error' => null,
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService();
        $result = $service->getLatestValues(['1', '2']);

        $this->assertEquals(10.5, $result['1']);
        $this->assertNull($result['2']);
    }

    public function test_request_throws_exception_on_zabbix_error()
    {
        Http::fake([
            '*/api_json_rpc.php*' => Http::response([
                'result' => null,
                'error' => ['code' => -32602, 'message' => 'Invalid params'],
                'id' => 1,
            ], 200),
        ]);

        $service = new ZabbixService();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid params');
        
        $service->request(['jsonrpc' => '2.0', 'method' => 'test', 'params' => [], 'id' => 1]);
    }
}
