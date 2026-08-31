<?php

namespace Tests\Feature;

use App\Services\ZabbixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

covers(\App\Console\Commands\SyncZabbix::class);

uses(TestCase::class, RefreshDatabase::class);

// Coverage gap (#494): the existing ScheduledJobInfrastructureTest only
// smoke-runs `zabbix:sync` against a real (missing) Zabbix. These tests pin
// down all three exit paths with a mocked service: unconfigured IDs, fetch
// failure, and the happy path. The command resolves ZabbixService via
// method injection, so we replace the container instance directly.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    config()->set('services.zabbix.out_item_id', null);
    config()->set('services.zabbix.in_item_id', null);
});

test('sync exits 1 and warns when item ids are not configured', function () {
    // Service must never be touched in this path.
    app()->instance(ZabbixService::class, \Mockery::mock(ZabbixService::class));

    $exitCode = Artisan::call('zabbix:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('not configured');
});

test('sync exits 1 when zabbix fetch fails', function () {
    config()->set('services.zabbix.out_item_id', '100');
    config()->set('services.zabbix.in_item_id', '200');

    $mock = \Mockery::mock(ZabbixService::class);
    $mock->shouldReceive('getInterfaceTraffic')->andThrow(new \RuntimeException('connection refused'));
    app()->instance(ZabbixService::class, $mock);

    $exitCode = Artisan::call('zabbix:sync');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('connection refused');
});

test('sync exits 0 and reports fetched record count on success', function () {
    config()->set('services.zabbix.out_item_id', '100');
    config()->set('services.zabbix.in_item_id', '200');

    $mock = \Mockery::mock(ZabbixService::class);
    $mock->shouldReceive('getInterfaceTraffic')->with('100')->andReturn([
        ['clock' => 1700000000, 'value' => '10'],
        ['clock' => 1700000060, 'value' => '20'],
    ]);
    $mock->shouldReceive('getInterfaceTraffic')->with('200')->andReturn([
        ['clock' => 1700000000, 'value' => '5'],
    ]);
    app()->instance(ZabbixService::class, $mock);

    $exitCode = Artisan::call('zabbix:sync');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Fetched 3 traffic records.')
        ->and($output)->toContain('Zabbix sync complete.');
});
