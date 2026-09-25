<?php

use App\Models\ZabbixDevice;
use App\Services\CacheInvalidationServiceInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ZabbixDeviceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seedLookupTables();

    config([
        'services.zabbix.url' => 'http://zabbix.test/api_jsonrpc.php',
        'services.zabbix.token' => 'test-token',
    ]);
});

// ── Seeding ──────────────────────────────────────────────────────────────

test('zabbix device seeder seeds every previously hardcoded device', function () {
    $this->seed(ZabbixDeviceSeeder::class);

    expect(ZabbixDevice::where('type', 'network')->count())->toBe(25)
        ->and(ZabbixDevice::where('type', 'wireless')->count())->toBe(14);

    $first = ZabbixDevice::where('type', 'network')->ordered()->first();
    expect($first->name)->toBe('فیبر اصلی')
        ->and($first->out_item_id)->toBe('73638')
        ->and($first->in_item_id)->toBe('73494')
        ->and($first->initial_duration)->toBe(7200);

    $firstWireless = ZabbixDevice::where('type', 'wireless')->ordered()->first();
    expect($firstWireless->name)->toBe('اعلایی')
        ->and($firstWireless->signal_item_id)->toBe('75297')
        ->and($firstWireless->frequency_item_id)->toBe('71725')
        ->and($firstWireless->response_item_id)->toBe('70996')
        ->and($firstWireless->min)->toBe(-85.0)
        ->and($firstWireless->max)->toBe(-45.0);
});

test('zabbix device seeder is idempotent', function () {
    $this->seed(ZabbixDeviceSeeder::class);
    $this->seed(ZabbixDeviceSeeder::class);

    expect(ZabbixDevice::count())->toBe(39);
});

// ── Route / permission ───────────────────────────────────────────────────

test('guest is redirected from zabbix devices page', function () {
    $this->get('/it/zabbix-devices')->assertRedirect('/login');
});

test('user without manage_zabbix permission is denied', function () {
    ['user' => $user] = $this->createUserWithUnit(['map']);
    $this->actingAs($user);

    $this->get('/it/zabbix-devices')->assertStatus(403);
});

test('user with manage_zabbix permission can open the page', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $this->get('/it/zabbix-devices')->assertStatus(200);
});

test('admin role receives the manage_zabbix permission', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::findByName('admin', 'web')->hasPermissionTo('manage_zabbix', 'web'))
        ->toBeTrue();
});

test('seeding permissions alone hands manage_zabbix to the admin role', function () {
    // Running only the permission seeder (no RoleSeeder) must still grant the
    // new permission, otherwise a standalone `db:seed --class=PermissionSeeder`
    // leaves admins without access to /it/zabbix-devices.
    $this->seed(PermissionSeeder::class);

    expect(Role::findByName('admin', 'web')->hasPermissionTo('manage_zabbix', 'web'))
        ->toBeTrue();
});

// ── CRUD ─────────────────────────────────────────────────────────────────

test('authorized user can create a network device', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'سویچ جدید')
        ->set('type', 'network')
        ->set('outItemId', '12345')
        ->set('inItemId', '54321')
        ->set('initialDuration', 7200)
        ->set('sortOrder', 5)
        ->call('createDevice')
        ->assertHasNoErrors();

    $device = ZabbixDevice::where('name', 'سویچ جدید')->first();
    expect($device)->not->toBeNull()
        ->and($device->type)->toBe('network')
        ->and($device->out_item_id)->toBe('12345')
        ->and($device->in_item_id)->toBe('54321')
        ->and($device->initial_duration)->toBe(7200)
        ->and($device->sort_order)->toBe(5)
        ->and($device->is_active)->toBeTrue();
});

test('authorized user can create a wireless device', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'points بی سیم')
        ->set('type', 'wireless')
        ->set('signalItemId', '111')
        ->set('frequencyItemId', '222')
        ->set('responseItemId', '333')
        ->call('createDevice')
        ->assertHasNoErrors();

    $device = ZabbixDevice::where('name', 'points بی سیم')->first();
    expect($device)->not->toBeNull()
        ->and($device->type)->toBe('wireless')
        ->and($device->min)->toBe(-85.0)
        ->and($device->max)->toBe(-45.0);
});

test('validation rejects non numeric item ids', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'تست نامعتبر')
        ->set('type', 'network')
        ->set('outItemId', 'abc')
        ->set('inItemId', '54321')
        ->call('createDevice')
        ->assertHasErrors(['outItemId']);

    expect(ZabbixDevice::count())->toBe(0);
});

test('validation requires the item ids that belong to the selected type', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'ناقص')
        ->set('type', 'wireless')
        ->set('signalItemId', '111')
        ->call('createDevice')
        ->assertHasErrors(['frequencyItemId', 'responseItemId']);

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'ناقص شبکه')
        ->set('type', 'network')
        ->set('outItemId', '111')
        ->call('createDevice')
        ->assertHasErrors(['inItemId']);
});

test('authorized user can update and deactivate a device', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create(['name' => 'قدیمی']);

    Livewire::test('it.zabbix-devices')
        ->call('editDevice', $device->id)
        ->assertSet('name', 'قدیمی')
        ->set('name', 'جدید')
        ->set('outItemId', '99999')
        ->call('updateDevice')
        ->assertHasNoErrors();

    $device->refresh();
    expect($device->name)->toBe('جدید')
        ->and($device->out_item_id)->toBe('99999');

    Livewire::test('it.zabbix-devices')
        ->call('toggle', $device->id);

    expect($device->refresh()->is_active)->toBeFalse();
});

test('authorized user can delete a device', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create();

    Livewire::test('it.zabbix-devices')->call('delete', $device->id);

    expect(ZabbixDevice::find($device->id))->toBeNull();
});

test('creating a device bumps the zabbix_devices cache namespace', function () {
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $cache = app(CacheInvalidationServiceInterface::class);
    $before = $cache->getVersion('zabbix_devices');

    Livewire::test('it.zabbix-devices')
        ->call('startCreate')
        ->set('name', 'کش تست')
        ->set('type', 'network')
        ->set('outItemId', '1')
        ->set('inItemId', '2')
        ->call('createDevice')
        ->assertHasNoErrors();

    expect($cache->getVersion('zabbix_devices'))->toBeGreaterThan($before);
});

// ── Display pages read from the database ─────────────────────────────────

test('networks page reads its items from the database', function () {
    $this->seed(ZabbixDeviceSeeder::class);
    ['user' => $user] = $this->createUserWithUnit(['map']);
    $this->actingAs($user);

    $items = Livewire::test('it.networks')->assertOk()->get('networkItems');

    expect(count($items))->toBe(25)
        ->and($items[0]['title'])->toBe('فیبر اصلی')
        ->and($items[0]['out-item-id'])->toBe('73638')
        ->and($items[0]['in-item-id'])->toBe('73494')
        ->and($items[0]['initial-duration'])->toBe('7200');
});

test('wireless page reads its items from the database', function () {
    $this->seed(ZabbixDeviceSeeder::class);
    ['user' => $user] = $this->createUserWithUnit(['map']);
    $this->actingAs($user);

    $items = Livewire::test('it.wireless')->assertOk()->get('signalItems');

    expect(count($items))->toBe(14)
        ->and($items[0]['name'])->toBe('اعلایی')
        ->and($items[0]['signalId'])->toBe('75297')
        ->and($items[0]['freqId'])->toBe('71725')
        ->and($items[0]['respId'])->toBe('70996');
});

test('display pages contain no hardcoded item id arrays', function () {
    $networks = file_get_contents(resource_path('views/livewire/it/networks.blade.php'));
    $wireless = file_get_contents(resource_path('views/livewire/it/wireless.blade.php'));

    expect($networks)->not->toContain('73638')
        ->and($networks)->not->toContain('76954')
        ->and($networks)->not->toContain('فیبر اصلی')
        ->and($networks)->toContain('ZabbixDevice::query()')
        ->and($wireless)->not->toContain('75297')
        ->and($wireless)->not->toContain('70996')
        ->and($wireless)->not->toContain('اعلایی')
        ->and($wireless)->toContain('ZabbixDevice::query()');
});

test('deactivated devices disappear from the display pages', function () {
    $this->seed(ZabbixDeviceSeeder::class);
    ['user' => $user] = $this->createUserWithUnit(['map']);
    $this->actingAs($user);

    ZabbixDevice::where('type', 'network')->ordered()->first()->update(['is_active' => false]);

    $items = Livewire::test('it.networks')->get('networkItems');

    expect(count($items))->toBe(24)
        ->and($items[0]['title'])->toBe('a سویچ');
});

test('device list is cached and refreshed after a write', function () {
    $this->seed(ZabbixDeviceSeeder::class);
    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    // warm the cache with the seeded list
    expect(count(Livewire::test('it.networks')->get('networkItems')))->toBe(25);

    ZabbixDevice::where('type', 'network')->ordered()->first()->update(['is_active' => false]);

    expect(count(Livewire::test('it.networks')->get('networkItems')))->toBe(24);
});

// ── Connection test ──────────────────────────────────────────────────────

test('connection test succeeds when zabbix returns values for every item', function () {
    Http::fake([
        '*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                ['itemid' => '12345', 'lastvalue' => '1.5'],
                ['itemid' => '54321', 'lastvalue' => '2.5'],
            ],
        ], 200),
    ]);

    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create([
        'type' => 'network',
        'out_item_id' => '12345',
        'in_item_id' => '54321',
    ]);

    $component = Livewire::test('it.zabbix-devices')->call('testConnection', $device->id);

    $component->assertStatus(200);

    $result = $component->get('connectionResults')[$device->id] ?? null;
    expect($result)->not->toBeNull()
        ->and($result['ok'])->toBeTrue();
});

test('connection test reports missing items instead of failing', function () {
    Http::fake([
        '*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                ['itemid' => '12345', 'lastvalue' => '1.5'],
            ],
        ], 200),
    ]);

    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create([
        'type' => 'network',
        'out_item_id' => '12345',
        'in_item_id' => '54321',
    ]);

    $component = Livewire::test('it.zabbix-devices')->call('testConnection', $device->id);
    $component->assertStatus(200);

    $result = $component->get('connectionResults')[$device->id] ?? null;
    expect($result)->not->toBeNull()
        ->and($result['ok'])->toBeFalse();
});

test('connection test never throws when zabbix is unreachable', function () {
    Http::fake(['*' => Http::response('', 500)]);

    ['user' => $user] = $this->createUserWithUnit(['manage_zabbix']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create([
        'type' => 'network',
        'out_item_id' => '12345',
        'in_item_id' => '54321',
    ]);

    $component = Livewire::test('it.zabbix-devices')->call('testConnection', $device->id);
    $component->assertStatus(200);

    $result = $component->get('connectionResults')[$device->id] ?? null;
    expect($result)->not->toBeNull()
        ->and($result['ok'])->toBeFalse()
        ->and($result['message'])->not->toBe('');
});

test('connection test is denied without manage_zabbix', function () {
    ['user' => $user] = $this->createUserWithUnit(['map']);
    $this->actingAs($user);

    $device = ZabbixDevice::factory()->create();

    Livewire::test('it.zabbix-devices')->call('testConnection', $device->id)->assertForbidden();
});
