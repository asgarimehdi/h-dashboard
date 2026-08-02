<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\ZabbixHost;
use App\Models\ZabbixItem;
use App\Models\ZabbixItemPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class ZabbixConfigTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;
    protected $eId;
    protected $sId;
    protected $rId;
    protected $unit;
    protected $user;
    protected $host;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();

        $this->tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $this->eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $this->sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $this->rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) random_int(1000000000, 2147483647);
        $this->unit = Unit::create(['name' => 'Test Unit']);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'Test',
            'l_name' => 'User',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $this->unit->id);

        // Create a Zabbix host in the user's unit
        $this->host = ZabbixHost::create([
            'unit_id' => $this->unit->id,
            'host_id' => '10084',
            'host_name' => 'core-switch-01',
            'visible_name' => 'سوئیچ اصلی',
            'ip' => '192.168.1.1',
            'status' => 'active',
        ]);
    }

    protected function authHeaders(): array
    {
        $token = $this->user->createToken('test')->plainTextToken;
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_unauthenticated_user_cannot_access_zabbix_config(): void
    {
        $this->getJson('/api/zabbix/hosts')->assertStatus(401);
        $this->getJson('/api/zabbix/items')->assertStatus(401);
        $this->getJson('/api/zabbix/pairs')->assertStatus(401);
    }

    public function test_hosts_index_returns_scoped_hosts(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/zabbix/hosts');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('سوئیچ اصلی', $response->json('data.0.visible_name'));
    }

    public function test_hosts_index_excludes_inaccessible_hosts(): void
    {
        // Create host in another unit
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        ZabbixHost::create([
            'unit_id' => $otherUnit->id,
            'host_id' => '99999',
            'host_name' => 'other-host',
            'visible_name' => 'هاست دیگر',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/zabbix/hosts');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_host_store_creates_host(): void
    {
        $response = $this->withHeaders($this->authHeaders())->postJson('/api/zabbix/hosts', [
            'unit_id' => $this->unit->id,
            'host_id' => '20000',
            'host_name' => 'new-host',
            'visible_name' => 'هاست جدید',
            'ip' => '10.0.0.5',
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('zabbix_hosts', ['host_id' => '20000']);
    }

    public function test_host_store_rejects_inaccessible_unit(): void
    {
        $otherUnit = Unit::create(['name' => 'Other Unit']);

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/zabbix/hosts', [
            'unit_id' => $otherUnit->id,
            'host_id' => '20001',
            'host_name' => 'blocked-host',
            'visible_name' => 'هاست ممنوع',
        ]);

        $response->assertStatus(403);
    }

    public function test_host_show_returns_items_and_pairs(): void
    {
        $item1 = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73638',
            'item_key' => 'net.if.out[eth0]',
            'name' => 'خروجی اترنت',
            'type' => 'traffic_out',
            'unit' => 'bps',
        ]);
        $item2 = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73639',
            'item_key' => 'net.if.in[eth0]',
            'name' => 'ورودی اترنت',
            'type' => 'traffic_in',
            'unit' => 'bps',
        ]);
        ZabbixItemPair::create([
            'zabbix_host_id' => $this->host->id,
            'name' => 'فیبر اصلی',
            'out_item_id' => $item1->id,
            'in_item_id' => $item2->id,
            'unit_id' => $this->unit->id,
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson("/api/zabbix/hosts/{$this->host->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertEquals(2, count($response->json('data.items')));
        $this->assertEquals(1, count($response->json('data.pairs')));
        $this->assertEquals('فیبر اصلی', $response->json('data.pairs.0.name'));
    }

    public function test_host_update_changes_fields(): void
    {
        $response = $this->withHeaders($this->authHeaders())->putJson("/api/zabbix/hosts/{$this->host->id}", [
            'visible_name' => 'سوئیچ اصلی ۲',
            'status' => 'maintenance',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('zabbix_hosts', [
            'id' => $this->host->id,
            'visible_name' => 'سوئیچ اصلی ۲',
            'status' => 'maintenance',
        ]);
    }

    public function test_host_destroy_deletes_host(): void
    {
        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/zabbix/hosts/{$this->host->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('zabbix_hosts', ['id' => $this->host->id]);
    }

    public function test_item_store_and_index(): void
    {
        $response = $this->withHeaders($this->authHeaders())->postJson('/api/zabbix/items', [
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73640',
            'item_key' => 'system.cpu.load',
            'name' => 'بار پردازنده',
            'type' => 'cpu',
            'unit' => '%',
            'is_monitored' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $list = $this->withHeaders($this->authHeaders())->getJson('/api/zabbix/items');
        $list->assertStatus(200);
        $this->assertEquals(1, $list->json('meta.total'));
        $this->assertEquals('بار پردازنده', $list->json('data.0.name'));
    }

    public function test_item_update_toggles_monitored(): void
    {
        $item = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73641',
            'item_key' => 'system.cpu.load',
            'name' => 'CPU Load',
            'type' => 'cpu',
            'is_monitored' => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/zabbix/items/{$item->id}", [
            'is_monitored' => false,
            'display_order' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('zabbix_items', [
            'id' => $item->id,
            'is_monitored' => false,
            'display_order' => 5,
        ]);
    }

    public function test_item_destroy_deletes_item(): void
    {
        $item = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73642',
            'item_key' => 'test.key',
            'name' => 'Test Item',
            'type' => 'custom',
        ]);

        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/zabbix/items/{$item->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('zabbix_items', ['id' => $item->id]);
    }

    public function test_pairs_index_returns_traffic_pairs(): void
    {
        $outItem = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73650',
            'item_key' => 'net.if.out[eth0]',
            'name' => 'Out',
            'type' => 'traffic_out',
        ]);
        $inItem = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73651',
            'item_key' => 'net.if.in[eth0]',
            'name' => 'In',
            'type' => 'traffic_in',
        ]);
        ZabbixItemPair::create([
            'zabbix_host_id' => $this->host->id,
            'name' => 'لینک اصلی',
            'out_item_id' => $outItem->id,
            'in_item_id' => $inItem->id,
            'unit_id' => $this->unit->id,
            'is_active' => true,
            'display_order' => 1,
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/zabbix/pairs');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('لینک اصلی', $response->json('data.0.name'));
        $this->assertEquals('Out', $response->json('data.0.out_item.name'));
        $this->assertEquals('In', $response->json('data.0.in_item.name'));
    }

    public function test_pair_store_validates_items_same_host(): void
    {
        // Create item on a DIFFERENT host
        $otherHost = ZabbixHost::create([
            'unit_id' => $this->unit->id,
            'host_id' => '30000',
            'host_name' => 'other',
            'visible_name' => 'دیگر',
            'status' => 'active',
        ]);
        $outItem = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73660',
            'item_key' => 'net.if.out[eth1]',
            'name' => 'Out1',
            'type' => 'traffic_out',
        ]);
        $inItem = ZabbixItem::create([
            'zabbix_host_id' => $otherHost->id,
            'item_id' => '73661',
            'item_key' => 'net.if.in[eth1]',
            'name' => 'In1',
            'type' => 'traffic_in',
        ]);

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/zabbix/pairs', [
            'zabbix_host_id' => $this->host->id,
            'name' => 'Pair Invalid',
            'out_item_id' => $outItem->id,
            'in_item_id' => $inItem->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_pair_destroy_deletes_pair(): void
    {
        $outItem = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73670',
            'item_key' => 'net.if.out[eth2]',
            'name' => 'Out2',
            'type' => 'traffic_out',
        ]);
        $inItem = ZabbixItem::create([
            'zabbix_host_id' => $this->host->id,
            'item_id' => '73671',
            'item_key' => 'net.if.in[eth2]',
            'name' => 'In2',
            'type' => 'traffic_in',
        ]);
        $pair = ZabbixItemPair::create([
            'zabbix_host_id' => $this->host->id,
            'name' => 'Pair To Delete',
            'out_item_id' => $outItem->id,
            'in_item_id' => $inItem->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/zabbix/pairs/{$pair->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('zabbix_item_pairs', ['id' => $pair->id]);
    }

    public function test_scope_enforced_on_other_unit_host(): void
    {
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $otherHost = ZabbixHost::create([
            'unit_id' => $otherUnit->id,
            'host_id' => '40000',
            'host_name' => 'blocked-host',
            'visible_name' => 'هاست دیگر',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->authHeaders())->getJson("/api/zabbix/hosts/{$otherHost->id}");
        $response->assertStatus(403);

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/zabbix/hosts/{$otherHost->id}", [
            'visible_name' => 'Hacked',
        ]);
        $response->assertStatus(403);

        $response = $this->withHeaders($this->authHeaders())->deleteJson("/api/zabbix/hosts/{$otherHost->id}");
        $response->assertStatus(403);
    }
}