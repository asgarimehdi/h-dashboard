<?php

namespace Tests\Feature;

use App\Console\Commands\SyncZabbix;
use App\Services\Zabbix\ZabbixClient;
use App\Services\Zabbix\ZabbixResult;
use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

covers(SyncZabbix::class);

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): the existing ScheduledJobInfrastructureTest only
// smoke-runs `zabbix:sync` against a real (missing) Zabbix. These tests pin
// down all three exit paths with a mocked client: unconfigured IDs, fetch
// failure, and the happy path. The command depends on the ZabbixClient
// boundary (#1411), so we replace that binding directly.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    config()->set('services.zabbix.out_item_id', null);
    config()->set('services.zabbix.in_item_id', null);
});

test('sync exits 1 and warns when item ids are not configured', function () {
    // Client must never be touched in this path.
    app()->instance(ZabbixClient::class, \Mockery::mock(ZabbixClient::class));

    $exitCode = Artisan::call('zabbix:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('not configured');
});

test('sync exits 1 when zabbix fetch fails', function () {
    config()->set('services.zabbix.out_item_id', '100');
    config()->set('services.zabbix.in_item_id', '200');

    $mock = \Mockery::mock(ZabbixClient::class);
    $mock->shouldReceive('traffic')->with('100')
        ->andReturn(ZabbixResult::fail('timeout', 'timed out'));
    app()->instance(ZabbixClient::class, $mock);

    $exitCode = Artisan::call('zabbix:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('timed out');
});

test('sync exits 0 and reports fetched record count on success', function () {
    config()->set('services.zabbix.out_item_id', '100');
    config()->set('services.zabbix.in_item_id', '200');

    $mock = \Mockery::mock(ZabbixClient::class);
    $mock->shouldReceive('traffic')->with('100')->andReturn(ZabbixResult::success([
        ['clock' => 1700000000, 'value' => '10'],
        ['clock' => 1700000060, 'value' => '20'],
    ]));
    $mock->shouldReceive('traffic')->with('200')->andReturn(ZabbixResult::success([
        ['clock' => 1700000000, 'value' => '5'],
    ]));
    app()->instance(ZabbixClient::class, $mock);

    $exitCode = Artisan::call('zabbix:sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Fetched 3 traffic records.')
        ->and($output)->toContain('Zabbix sync complete.');
});

test('sync branches on a failure wrapped from a throwing service', function () {
    // Executable proof that ServiceZabbixClient's compatibility with a bound
    // ZabbixService mock survives the re-route, and that the command no longer
    // needs its own catch (\Throwable).
    config()->set('services.zabbix.out_item_id', '100');
    config()->set('services.zabbix.in_item_id', '200');

    $service = \Mockery::mock(ZabbixService::class);
    $service->shouldReceive('getInterfaceTraffic')
        ->andThrow(new \RuntimeException('connection refused'));
    app()->instance(ZabbixService::class, $service);

    $exitCode = Artisan::call('zabbix:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('connection refused');
});
