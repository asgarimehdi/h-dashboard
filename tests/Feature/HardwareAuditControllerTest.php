<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HardwareAuditController;
use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Permission;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HardwareAuditController::class);

class HardwareAuditControllerTest extends TestCase
{
    use InteractsWithTestSetup;
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

        $this->seedLookupTables();

        $nCode = (string) fake()->unique()->numerify('##########');
        $this->unit = Unit::create(['name' => 'Test Unit']);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'Test',
            'l_name' => 'User',
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $this->unit->id,
        ]);
        $this->user = User::create([
            'n_code' => $nCode,
            'password' => Hash::make('password'),
        ]);
        $this->user->units()->attach($this->unit->id, ['role' => 'staff', 'is_primary' => true]);
        Permission::firstOrCreate(['name' => 'manage_hardware']);
        $this->user->givePermissionTo('manage_hardware');
        Session::put('current_unit_id', $this->unit->id);

        $this->hardware = Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'TEST-PC-001',
            'type' => 'pc',
            'os' => 'Windows 11',
            'cpu' => 'Intel i5',
            'ram' => '8192',
            'hdd' => 'SSD 256GB',
            'mark' => false,
            'shutdown' => false,
        ]);
    }

    private function createTokenWithAbilities(array $abilities, array $permissions = []): string
    {
        $args = [];
        if ($permissions) {
            $args['permissions'] = $permissions;
        }
        ['user' => $user] = $this->createUserWithUnit(...$args);

        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    private function createTokenForUser(User $user, array $abilities): string
    {
        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    private function apiGet(string $url, string $token)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    private function apiPost(string $url, array $data, string $token)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson($url, $data);
    }

    public function test_show_returns_single_audit_with_full_diff(): void
    {
        $this->hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits/{$audit->id}", $token);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $audit->id)
            ->assertJsonStructure(['data' => ['diff_summary', 'action_label', 'source_label']]);
        $this->assertIsArray($response->json('data.diff_summary'));
    }

    public function test_show_returns_404_when_audit_belongs_to_another_hardware(): void
    {
        $otherHardware = Hardware::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'pc_name' => 'OTHER-PC',
            'type' => 'laptop',
        ]);
        $otherAudit = HardwareAudit::where('hardware_id', $otherHardware->id)
            ->where('action', 'created')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits/{$otherAudit->id}", $token);

        $response->assertStatus(404);
    }

    public function test_rollback_requires_field(): void
    {
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [], $token);

        $response->assertStatus(422);
    }

    public function test_rollback_returns_422_when_field_not_in_audit(): void
    {
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
            'field' => 'switch',
        ], $token);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Field not found in audit record.');
    }

    public function test_rollback_restores_boolean_field(): void
    {
        $this->hardware->update(['mark' => true]);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", [
            'field' => 'mark',
        ], $token);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('hardwares', [
            'id' => $this->hardware->id,
            'mark' => false,
        ]);
    }

    public function test_export_endpoint_returns_downloadable_file(): void
    {
        $this->hardware->update(['cpu' => 'Intel i9']);

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits/export", $token);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('content-disposition')
        );
    }

    public function test_export_with_csv_format(): void
    {
        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits/export?format=csv", $token);

        $response->assertStatus(200);
    }

    public function test_restore_record_recreates_deleted_hardware(): void
    {
        $createdAudit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();
        $this->hardware->forceDelete();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('hardwares', [
            'pc_name' => 'TEST-PC-001',
            'cpu' => 'Intel i5',
        ]);
    }

    public function test_restore_record_rejects_non_created_audit(): void
    {
        $this->hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/audits/{$audit->id}/restore-record", [], $token);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Only "created" audits can be used to restore a record.');
    }

    public function test_restore_record_rejects_if_hardware_still_exists(): void
    {
        $createdAudit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This hardware record still exists — use rollback instead.');
    }

    public function test_index_filters_by_user_id_and_date_range(): void
    {
        $this->hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits?user_id={$audit->user_id}", $token);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $entry) {
            $this->assertEquals($audit->user_id, $entry['user']['id'] ?? $audit->user_id);
        }
    }

    public function test_index_filters_invalid_field_returns_empty(): void
    {
        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiGet("/api/hardware/{$this->hardware->id}/audits?field=nonexistent_field", $token);

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('meta.total'));
    }

    // ──────────────────────────────────────────────
    // Explicit-ability token tests
    // ──────────────────────────────────────────────

    public function test_rollback_denied_with_read_only_token(): void
    {
        $this->hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiPost("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", ['field' => 'cpu'], $token);
        $response->assertForbidden();
    }

    public function test_rollback_allowed_with_write_ability(): void
    {
        $this->hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/{$this->hardware->id}/audits/{$audit->id}/rollback", ['field' => 'cpu'], $token);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_restore_record_denied_with_read_only_token(): void
    {
        $createdAudit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();
        $this->hardware->forceDelete();

        $token = $this->createTokenForUser($this->user, ['hardware:read']);
        $response = $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token);
        $response->assertForbidden();
    }

    public function test_restore_record_allowed_with_write_ability(): void
    {
        $createdAudit = HardwareAudit::where('hardware_id', $this->hardware->id)
            ->where('action', 'created')
            ->first();
        $this->hardware->forceDelete();

        $token = $this->createTokenForUser($this->user, ['hardware:read', 'hardware:write']);
        $response = $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token);
        $this->assertNotEquals(403, $response->getStatusCode());
    }
}
