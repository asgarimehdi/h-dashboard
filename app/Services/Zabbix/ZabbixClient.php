<?php

namespace App\Services\Zabbix;

/**
 * The Zabbix transport boundary (#741): two read operations, both returning a
 * typed {@see ZabbixResult} instead of throwing.
 *
 * Controllers depend on this interface and only map results to HTTP; the
 * connection layer itself is unit-testable with a stub, no Zabbix server.
 */
interface ZabbixClient
{
    /**
     * Throughput points for one item over the last $duration seconds
     * (the `traffic` shape: [['x' => ms, 'y' => mbps], ...]).
     */
    public function traffic(int|string $itemId, int $duration = 3600): ZabbixResult;

    /**
     * Latest value per requested item id (missing items map to null).
     *
     * @param  array<int, string>  $itemIds
     */
    public function latestValues(array $itemIds): ZabbixResult;
}
