<?php

namespace App\Services\Zabbix;

use App\Services\ZabbixService;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * {@see ZabbixClient} over the existing {@see ZabbixService}.
 *
 * ZabbixService throws plain exceptions (`Zabbix API HTTP error: …`,
 * `Zabbix API returned invalid JSON`, cURL failures from Laravel's Http
 * client). This class is the single place those get classified into the
 * typed failure kinds, so no controller ever sees an exception from the
 * transport again.
 *
 * It deliberately wraps whatever ZabbixService instance the container gives:
 * existing tests bind a Mockery mock for `ZabbixService::class` and still
 * expect their exact calls (`->once()`, `->with(...)`) — the wrapper resolves
 * the service lazily at call time, so those mocks keep working untouched.
 */
class ServiceZabbixClient implements ZabbixClient
{
    public function __construct(private readonly ZabbixService $zabbix) {}

    public function traffic(int|string $itemId, int $duration = 3600): ZabbixResult
    {
        try {
            return ZabbixResult::success($this->zabbix->getInterfaceTraffic($itemId, $duration));
        } catch (Throwable $e) {
            return self::from($e);
        }
    }

    public function latestValues(array $itemIds): ZabbixResult
    {
        try {
            return ZabbixResult::success($this->zabbix->getLatestValues($itemIds));
        } catch (Throwable $e) {
            return self::from($e);
        }
    }

    /**
     * Classify a transport exception into a failure kind.
     *
     * Order matters: a cURL timeout IS a ConnectionException whose message
     * says "timed out" — the message check must win, otherwise every timeout
     * reports as a plain outage and the dashboard can't tell them apart.
     */
    private static function from(Throwable $e): ZabbixResult
    {
        $message = $e->getMessage();

        if (preg_match('/tim(?:ed? ?out|eout)/i', $message) === 1) {
            return ZabbixResult::fail('timeout', $message);
        }

        if (
            $e instanceof ConnectionException
            || preg_match('/connect/i', $message) === 1
            || str_contains($message, 'HTTP error')
        ) {
            return ZabbixResult::fail('connection', $message);
        }

        if (str_contains($message, 'invalid JSON') || str_contains($message, 'unexpected')) {
            return ZabbixResult::fail('invalid_response', $message);
        }

        return ZabbixResult::fail('error', $message);
    }
}
