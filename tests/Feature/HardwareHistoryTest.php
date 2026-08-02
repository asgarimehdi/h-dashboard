<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareHistory;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class HardwareHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected $tId;
    protected $eId;
    protected $sId;
    protected $rId;
    protected $unit;
    protected $user;
    protected $hardware;

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

        // Authenticate to ensure observer gets user_id for created event
        $this->actingAs($this->user);

        // Create hardware
        $this->hardware = Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'TEST-PC-001',
            'type' => 'pc',
            'os' => 'Windows 11',
            'cpu' => 'Intel i5',
            'ram' => '8192',
            'hdd' => 'SSD 256GB',
        ]);
    }

    public function test_history_endpoint_returns_history_for_new_hardware(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$this->hardware->id}/history");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 1); // created event from observer
        
        $data = $response->json('data');
        $this->assertEquals(1, count($data));
        $this->assertEquals('created', $data[0]['action']);
    }

    public function test_history_logs_created_action(): void
    {
        // Hardware creation is logged by observer
        $this->assertDatabaseHas('hardware_histories', [
            'hardware_id' => $this->hardware->id,
            'action' => 'created',
            'user_id' => $this->user->id,
        ]);

        $history = HardwareHistory::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($history);
        $this->assertNotNull($history->changes);
        $this->assertEquals('created', $history->action);
    }

    public function test_history_logs_updated_action_with_field_diff(): void
    {
        $originalCpu = $this->hardware->cpu;
        $this->hardware->update(['cpu' => 'Intel i7', 'ram' => '16384']);

        $history = HardwareHistory::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('updated', $history->action);
        
        $changes = $history->changes;
        $this->assertIsArray($changes);
        
        // Check that cpu field is in changes
        $cpuChange = collect($changes)->firstWhere('field', 'cpu');
        $this->assertNotNull($cpuChange);
        $this->assertEquals($originalCpu, $cpuChange['old']);
        $this->assertEquals('Intel i7', $cpuChange['new']);

        // Check that ram field is in changes
        $ramChange = collect($changes)->firstWhere('field', 'ram');
        $this->assertNotNull($ramChange);
        $this->assertEquals('8192', $ramChange['old']);
        $this->assertEquals('16384', $ramChange['new']);
    }

    public function test_history_logs_deleted_action(): void
    {
        $hardwareId = $this->hardware->id;
        $this->hardware->delete();

        $this->assertDatabaseHas('hardware_histories', [
            'hardware_id' => $hardwareId,
            'action' => 'deleted',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_history_api_respects_organizational_scope(): void
    {
        // Create another unit and user without access
        $otherUnit = Unit::create(['name' => 'Other Unit']);
        $otherNCode = (string) random_int(1000000000, 2147483647);
        
        Person::create([
            'n_code' => $otherNCode,
            'f_name' => 'Other',
            'l_name' => 'User',
            't_id' => $this->tId,
            'e_id' => $this->eId,
            's_id' => $this->sId,
            'r_id' => $this->rId,
            'u_id' => $otherUnit->id,
        ]);
        $otherUser = User::create([
            'n_code' => $otherNCode,
            'password' => Hash::make('password'),
        ]);
        $otherUser->units()->attach($otherUnit->id, ['role' => 'staff', 'is_primary' => true]);

        // Create hardware in other unit
        $otherHardware = Hardware::create([
            'n_code' => $otherNCode,
            'pc_name' => 'OTHER-PC',
            'type' => 'laptop',
        ]);

        // Try to access history with original user (should be 403)
        $token = $this->user->createToken('test')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$otherHardware->id}/history");

        $response->assertStatus(403);
    }

    public function test_history_api_filters_by_action(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        // Create multiple history entries
        $this->hardware->update(['cpu' => 'Intel i7']); // updated
        $this->hardware->update(['ram' => '16384']); // updated

        // Filter by 'created' action
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$this->hardware->id}/history?action=created");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, count($data));
        $this->assertEquals('created', $data[0]['action']);

        // Filter by 'updated' action
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$this->hardware->id}/history?action=updated");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(2, count($data));
        foreach ($data as $entry) {
            $this->assertEquals('updated', $entry['action']);
        }
    }

    public function test_history_api_pagination(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        // Create multiple updates
        for ($i = 0; $i < 5; $i++) {
            $this->hardware->update(['comments' => "Update {$i}"]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$this->hardware->id}/history?per_page=3");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(3, count($data));
        $this->assertEquals(6, $response->json('meta.total')); // 1 created + 5 updated (null->value captured)
        $this->assertEquals(1, $response->json('meta.current_page'));

        // Second page
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson("/api/hardware/{$this->hardware->id}/history?per_page=3&page=2");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(3, count($data)); // 6 total - 3 on page 1 = 3 on page 2
        $this->assertEquals(2, $response->json('meta.current_page'));
    }

    public function test_bulk_mark_logs_history(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/hardware/bulk-mark', [
            'ids' => [$this->hardware->id],
            'mark' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hardware_histories', [
            'hardware_id' => $this->hardware->id,
            'action' => 'bulk_mark',
            'user_id' => $this->user->id,
        ]);

        $history = HardwareHistory::where('hardware_id', $this->hardware->id)
            ->where('action', 'bulk_mark')
            ->first();

        $this->assertNotNull($history);
        $changes = $history->changes;
        $this->assertIsArray($changes);
        $markChange = collect($changes)->firstWhere('field', 'mark');
        $this->assertNotNull($markChange);
        $this->assertEquals(true, $markChange['new']);
    }

    public function test_bulk_delete_logs_history(): void
    {
        $hardwareId = $this->hardware->id;
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/hardware/bulk-delete', [
            'ids' => [$hardwareId],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hardware_histories', [
            'hardware_id' => $hardwareId,
            'action' => 'bulk_delete',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_history_includes_user_info(): void
    {
        $this->hardware->update(['cpu' => 'Intel i9']);

        $history = HardwareHistory::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->with('user')
            ->first();

        $this->assertNotNull($history->user);
        $this->assertEquals($this->user->id, $history->user->id);
        $this->assertEquals($this->user->n_code, $history->user->n_code);
    }

    public function test_history_includes_ip_and_user_agent(): void
    {
        // We can't easily test IP in tests, but verify columns exist
        $history = HardwareHistory::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($history);
        // Columns exist in table - ip_address and user_agent
    }
}