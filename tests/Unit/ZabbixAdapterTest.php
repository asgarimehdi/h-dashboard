<?php

namespace Tests\Unit;

use App\Services\Zabbix\ServiceZabbixClient;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixResult;
use App\Services\ZabbixService;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

covers(ServiceZabbixClient::class);
covers(ZabbixResult::class);

/**
 * #741: the connection layer is a typed value, testable without a Zabbix
 * server. Every scenario below runs against an in-memory stub service —
 * outage, timeout and invalid response included.
 */
class ZabbixAdapterTest extends TestCase
{
    /**
     * A ZabbixService that throws the given exception instead of talking
     * to anything (overrides both methods the adapter calls).
     */
    private function throwingService(Throwable $e): ZabbixService
    {
        return new class($e) extends ZabbixService
        {
            public function __construct(private readonly Throwable $e) {}

            public function getInterfaceTraffic($itemId, $duration = 3600)
            {
                throw $this->e;
            }

            public function getLatestValues(array $itemIds): array
            {
                throw $this->e;
            }
        };
    }

    private function workingService(): ZabbixService
    {
        return new class extends ZabbixService
        {
            public function __construct() {}

            public function getInterfaceTraffic($itemId, $duration = 3600)
            {
                return [['x' => 1, 'y' => 2.5]];
            }

            public function getLatestValues(array $itemIds): array
            {
                return ['100' => 1.5, '200' => null];
            }
        };
    }

    public function test_success_wraps_the_payload(): void
    {
        $client = new ServiceZabbixClient($this->workingService());

        $traffic = $client->traffic(100, 3600);
        $this->assertTrue($traffic->isOk());
        $this->assertFalse($traffic->failed());
        $this->assertSame([['x' => 1, 'y' => 2.5]], $traffic->data());
        $this->assertNull($traffic->failure());

        $latest = $client->latestValues(['100', '200']);
        $this->assertTrue($latest->isOk());
        $this->assertSame(['100' => 1.5, '200' => null], $latest->data());
    }

    public function test_timeout_is_classified_as_timeout(): void
    {
        $client = new ServiceZabbixClient($this->throwingService(
            new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received')
        ));

        $result = $client->traffic(100);

        $this->assertTrue($result->failed());
        $this->assertSame('timeout', $result->failure());
        $this->assertNull($result->data());
        $this->assertStringContainsString('timed out', $result->message());
    }

    public function test_connection_refused_is_classified_as_connection(): void
    {
        $client = new ServiceZabbixClient($this->throwingService(
            new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 9000: Connection refused')
        ));

        $result = $client->latestValues(['100']);

        $this->assertTrue($result->failed());
        $this->assertSame('connection', $result->failure());
    }

    public function test_http_error_status_is_classified_as_connection(): void
    {
        // What ZabbixService throws for a non-2xx API response.
        $client = new ServiceZabbixClient($this->throwingService(
            new RuntimeException('Zabbix API HTTP error: 503')
        ));

        $this->assertSame('connection', $client->traffic(100)->failure());
    }

    public function test_invalid_json_is_classified_as_invalid_response(): void
    {
        // What ZabbixService throws when the body is not JSON at all.
        $client = new ServiceZabbixClient($this->throwingService(
            new RuntimeException('Zabbix API returned invalid JSON')
        ));

        $result = $client->latestValues(['100']);

        $this->assertTrue($result->failed());
        $this->assertSame('invalid_response', $result->failure());
    }

    public function test_unexpected_errors_fall_back_to_error(): void
    {
        $client = new ServiceZabbixClient($this->throwingService(
            new RuntimeException('something else entirely')
        ));

        $this->assertSame('error', $client->traffic(100)->failure());
    }

    public function test_a_stub_client_satisfies_the_interface_contract(): void
    {
        // The shape controllers rely on: a stub can produce both outcomes
        // with no server, which is the whole point of the extraction (#741).
        $stub = new class implements ZabbixClient
        {
            public function traffic(int|string $itemId, int $duration = 3600): ZabbixResult
            {
                return ZabbixResult::fail('timeout', 'stub timeout');
            }

            public function latestValues(array $itemIds): ZabbixResult
            {
                return ZabbixResult::success(['100' => 42.0]);
            }
        };

        $this->assertTrue($stub->traffic(1)->failed());
        $this->assertSame('timeout', $stub->traffic(1)->failure());
        $this->assertSame(['100' => 42.0], $stub->latestValues(['100'])->data());
    }
}
