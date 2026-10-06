<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\HardwareAudit;
use App\Models\Person;
use App\Models\Todo;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class ApiAbilityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create a user with a real Sanctum token bearing specific abilities.
     * Optionally assign Spatie permissions so role_or_permission middleware passes.
     */
    private function createTokenWithAbilities(array $abilities, array $permissions = []): string
    {
        $args = [];
        if ($permissions) {
            $args['permissions'] = $permissions;
        }
        ['user' => $user] = $this->createUserWithUnit(...$args);

        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    private function apiGet(string $url, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    private function apiPost(string $url, array $data, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson($url, $data);
    }

    private function apiPut(string $url, array $data, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->putJson($url, $data);
    }

    private function apiDelete(string $url, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson($url);
    }

    private function flutterAbilities(): array
    {
        return [
            'units:read',
            'hardware:read', 'hardware:write',
            'tickets:read', 'tickets:write',
            'persons:read', 'persons:write',
            'todos:read', 'todos:write',
            'hr:read',
            'notifications:read',
            'gis:read',
            'reports:read',
            'traffic:read',
        ];
    }

    private function createHardwareForUnit(int $unitId, array $data = []): Hardware
    {
        $person = Person::factory()->create(['u_id' => $unitId]);
        $data['n_code'] = $person->n_code;

        return Hardware::factory()->create($data);
    }

    // ──────────────────────────────────────────────
    // Units
    // ──────────────────────────────────────────────

    public function test_units_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/units', $token)->assertForbidden();
    }

    public function test_units_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['units:read'], ['organization']);
        $this->apiGet('/api/units', $token)->assertOk();
    }

    public function test_units_write_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['units:read'], ['organization']);
        $this->apiPost('/api/units', ['name' => 'Test'], $token)->assertForbidden();
    }

    public function test_units_write_allowed_with_ability(): void
    {
        $unitTypeId = DB::table('unit_types')->insertGetId(['name' => 'Test Type']);
        $token = $this->createTokenWithAbilities(['units:read', 'units:write'], ['organization']);
        $this->apiPost('/api/units', [
            'name' => 'Test Unit',
            'unit_type_id' => $unitTypeId,
        ], $token)->assertCreated();
    }

    // ──────────────────────────────────────────────
    // Hardware
    // ──────────────────────────────────────────────

    public function test_hardware_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['units:read']);
        $this->apiGet('/api/hardware', $token)->assertForbidden();
    }

    public function test_hardware_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['manage_hardware']);
        $this->apiGet('/api/hardware', $token)->assertOk();
    }

    public function test_hardware_write_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['manage_hardware']);
        $this->apiPost('/api/hardware', ['n_code' => '123', 'pc_name' => 'test'], $token)->assertForbidden();
    }

    public function test_hardware_audit_rollback_denied_with_read_only_token(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['manage_hardware']);
        $hardware = $this->createHardwareForUnit($user->units->first()->id);
        $hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $this->apiPost("/api/hardware/{$hardware->id}/audits/{$audit->id}/rollback", ['field' => 'cpu'], $token)->assertForbidden();
    }

    public function test_hardware_audit_rollback_allowed_with_write_ability(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['manage_hardware']);
        $hardware = $this->createHardwareForUnit($user->units->first()->id);
        $hardware->update(['cpu' => 'Intel i7']);
        $audit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'updated')
            ->first();

        $token = $user->createToken('test-token', ['hardware:read', 'hardware:write'])->plainTextToken;
        $response = $this->apiPost("/api/hardware/{$hardware->id}/audits/{$audit->id}/rollback", ['field' => 'cpu'], $token);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_hardware_audit_restore_record_denied_with_read_only_token(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['manage_hardware']);
        $hardware = $this->createHardwareForUnit($user->units->first()->id);
        $createdAudit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'created')
            ->first();
        $hardware->forceDelete();

        $token = $user->createToken('test-token', ['hardware:read'])->plainTextToken;
        $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token)->assertForbidden();
    }

    public function test_hardware_audit_restore_record_allowed_with_write_ability(): void
    {
        ['user' => $user] = $this->createUserWithUnit(permissions: ['manage_hardware']);
        $hardware = $this->createHardwareForUnit($user->units->first()->id);
        $createdAudit = HardwareAudit::where('hardware_id', $hardware->id)
            ->where('action', 'created')
            ->first();
        $hardware->forceDelete();

        $token = $user->createToken('test-token', ['hardware:read', 'hardware:write'])->plainTextToken;
        $response = $this->apiPost("/api/hardware/audits/{$createdAudit->id}/restore-record", [], $token);
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // Tickets
    // ──────────────────────────────────────────────

    public function test_tickets_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['view_all_tickets']);
        $this->apiGet('/api/tickets', $token)->assertForbidden();
    }

    public function test_tickets_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['tickets:read'], ['view_all_tickets']);
        $this->apiGet('/api/tickets', $token)->assertOk();
    }

    public function test_tickets_read_denied_without_permission(): void
    {
        $token = $this->createTokenWithAbilities(['tickets:read']);
        $this->apiGet('/api/tickets', $token)->assertForbidden();
    }

    public function test_tickets_write_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['tickets:read'], ['create_ticket']);
        $this->apiPost('/api/tickets', ['title' => 'Test'], $token)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // Persons
    // ──────────────────────────────────────────────

    public function test_persons_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/persons', $token)->assertForbidden();
    }

    public function test_persons_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['persons:read'], ['kargozini']);
        $this->apiGet('/api/persons', $token)->assertOk();
    }

    public function test_persons_write_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['persons:read'], ['manage_personnel']);
        $this->apiPost('/api/persons', ['n_code' => '123'], $token)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // Todos
    // ──────────────────────────────────────────────

    public function test_todos_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['calendar']);
        $this->apiGet('/api/todos', $token)->assertForbidden();
    }

    public function test_todos_read_allowed_with_todos_read(): void
    {
        $token = $this->createTokenWithAbilities(['todos:read'], ['calendar']);
        $this->apiGet('/api/todos', $token)->assertOk();
    }

    public function test_todos_read_allowed_with_todos_write(): void
    {
        $token = $this->createTokenWithAbilities(['todos:write'], ['calendar']);
        $this->apiGet('/api/todos', $token)->assertOk();
    }

    public function test_todos_read_denied_without_permission(): void
    {
        $token = $this->createTokenWithAbilities(['todos:read']);
        $this->apiGet('/api/todos', $token)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // Todos writes (Plan 48, issue #837)
    // ──────────────────────────────────────────────

    private function createTodoInOwnUnit(): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(permissions: ['calendar']);
        $todo = Todo::factory()->create(['unit_id' => $unit->id]);

        return ['user' => $user, 'unit' => $unit, 'todo' => $todo];
    }

    private function todoPayload(int $unitId): array
    {
        return [
            'title' => 'Ability gated todo',
            'start_at' => '2026-07-15 10:00:00',
            'unit_id' => $unitId,
        ];
    }

    public function test_todos_store_denied_with_read_only_ability(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createTodoInOwnUnit();
        $token = $user->createToken('test-token', ['todos:read'])->plainTextToken;
        $this->apiPost('/api/todos', $this->todoPayload($unit->id), $token)->assertForbidden();
    }

    public function test_todos_update_denied_with_read_only_ability(): void
    {
        ['user' => $user, 'todo' => $todo] = $this->createTodoInOwnUnit();
        $token = $user->createToken('test-token', ['todos:read'])->plainTextToken;
        $this->apiPut("/api/todos/{$todo->id}", ['title' => 'Blocked rename'], $token)->assertForbidden();
    }

    public function test_todos_destroy_denied_with_read_only_ability(): void
    {
        ['user' => $user, 'todo' => $todo] = $this->createTodoInOwnUnit();
        $token = $user->createToken('test-token', ['todos:read'])->plainTextToken;
        $this->apiDelete("/api/todos/{$todo->id}", $token)->assertForbidden();
    }

    public function test_todos_toggle_complete_denied_with_read_only_ability(): void
    {
        ['user' => $user, 'todo' => $todo] = $this->createTodoInOwnUnit();
        $token = $user->createToken('test-token', ['todos:read'])->plainTextToken;
        $this->apiPost("/api/todos/{$todo->id}/toggle-complete", [], $token)->assertForbidden();
    }

    public function test_todos_writes_allowed_with_write_only_ability(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createTodoInOwnUnit();
        $writeToken = $user->createToken('test-token', ['todos:write'])->plainTextToken;

        $store = $this->apiPost('/api/todos', $this->todoPayload($unit->id), $writeToken);
        $store->assertCreated();
        $todoId = $store->json('data.id');
        $this->assertNotNull($todoId);

        $this->apiPut("/api/todos/{$todoId}", ['title' => 'Renamed by writer'], $writeToken)->assertOk();
        $this->apiPost("/api/todos/{$todoId}/toggle-complete", [], $writeToken)->assertOk();
        $this->apiDelete("/api/todos/{$todoId}", $writeToken)->assertOk();
    }

    // ──────────────────────────────────────────────
    // HR
    // ──────────────────────────────────────────────

    public function test_hr_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['view_hr_dashboard']);
        $this->apiGet('/api/hr/stats', $token)->assertForbidden();
    }

    public function test_hr_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hr:read'], ['view_hr_dashboard']);
        $this->apiGet('/api/hr/stats', $token)->assertOk();
    }

    public function test_hr_read_denied_without_permission(): void
    {
        $token = $this->createTokenWithAbilities(['hr:read']);
        $this->apiGet('/api/hr/stats', $token)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // Reports
    // ──────────────────────────────────────────────

    public function test_reports_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/reports/units', $token)->assertForbidden();
    }

    public function test_reports_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['reports:read'], ['manage_personnel']);
        $this->apiGet('/api/reports/units', $token)->assertOk();
    }

    // ──────────────────────────────────────────────
    // Notifications
    // ──────────────────────────────────────────────

    public function test_notifications_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/notifications', $token)->assertForbidden();
    }

    public function test_notifications_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['notifications:read']);
        $this->apiGet('/api/notifications', $token)->assertOk();
    }

    // ──────────────────────────────────────────────
    // GIS
    // ──────────────────────────────────────────────

    public function test_gis_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['map']);
        $this->apiGet('/api/gis/stats', $token)->assertForbidden();
    }

    public function test_gis_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['gis:read'], ['map']);
        $this->apiGet('/api/gis/stats', $token)->assertOk();
    }

    public function test_gis_read_denied_without_permission(): void
    {
        $token = $this->createTokenWithAbilities(['gis:read']);
        $this->apiGet('/api/gis/stats', $token)->assertForbidden();
    }

    // ──────────────────────────────────────────────
    // Traffic
    // ──────────────────────────────────────────────

    public function test_traffic_read_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/zabbix/multi-latest', $token)->assertForbidden();
    }

    public function test_traffic_read_allowed_with_ability(): void
    {
        $token = $this->createTokenWithAbilities(['traffic:read'], ['map']);
        // Multi-latest requires item_ids — 422 means ability check passed, validation ran next
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);
    }

    // ──────────────────────────────────────────────
    // Legacy wildcard token
    // ──────────────────────────────────────────────

    public function test_legacy_wildcard_token_has_full_access(): void
    {
        ['user' => $user] = $this->createUserWithUnit(
            permissions: [
                'view_all_tickets', 'create_ticket', 'manage_unit_tickets',
                'calendar', 'view_hr_dashboard', 'map',
                'organization', 'manage_hardware', 'manage_personnel',
            ]
        );
        $token = $user->createToken('legacy', ['*'])->plainTextToken;

        $this->apiGet('/api/units', $token)->assertOk();
        $this->apiGet('/api/hardware', $token)->assertOk();
        $this->apiGet('/api/tickets', $token)->assertOk();
        $this->apiGet('/api/persons', $token)->assertOk();
        $this->apiGet('/api/todos', $token)->assertOk();
        $this->apiGet('/api/hr/stats', $token)->assertOk();
        $this->apiGet('/api/reports/units', $token)->assertOk();
        $this->apiGet('/api/notifications', $token)->assertOk();
        $this->apiGet('/api/gis/stats', $token)->assertOk();
        // Multi-latest requires item_ids — 422 means ability check passed
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);
    }

    // ──────────────────────────────────────────────
    // Full Flutter token smoke pass
    // ──────────────────────────────────────────────

    public function test_flutter_token_full_smoke_pass(): void
    {
        ['user' => $user] = $this->createUserWithUnit(
            permissions: [
                'view_all_tickets', 'create_ticket', 'manage_unit_tickets',
                'calendar', 'view_hr_dashboard', 'map',
                'organization', 'manage_hardware', 'manage_personnel',
            ]
        );
        $token = $user->createToken('flutter', $this->flutterAbilities())->plainTextToken;

        $this->apiGet('/api/units', $token)->assertOk();
        $this->apiGet('/api/hardware', $token)->assertOk();
        $this->apiGet('/api/hardware/stats', $token)->assertOk();
        $this->apiGet('/api/tickets', $token)->assertOk();
        $this->apiGet('/api/persons', $token)->assertOk();
        $this->apiGet('/api/todos', $token)->assertOk();
        $this->apiGet('/api/hr/stats', $token)->assertOk();
        $this->apiGet('/api/hr/org-chart', $token)->assertOk();
        $this->apiGet('/api/reports/units', $token)->assertOk();
        $this->apiGet('/api/notifications', $token)->assertOk();
        $this->apiGet('/api/gis/stats', $token)->assertOk();
        // Multi-latest requires item_ids — 422 means ability check passed
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);
    }

    // ──────────────────────────────────────────────
    // Unauthenticated
    // ──────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/units')->assertUnauthorized();
    }

    // ──────────────────────────────────────────────
    // Token abilities derived from user permissions (Plan 003)
    // ──────────────────────────────────────────────

    /**
     * Get the raw abilities array from a user's login token by hitting /api/login.
     * Returns the token string; decode it to inspect abilities if needed.
     */
    private function loginAndGetToken(string $nCode, string $password = 'password'): string
    {
        $response = $this->postJson('/api/login', [
            'n_code' => $nCode,
            'password' => $password,
        ]);
        $response->assertOk();

        return $response->json('token');
    }

    /**
     * Decode a Sanctum token's abilities by looking up the PersonalAccessToken.
     * This avoids JWT parsing and uses Laravel's token storage directly.
     */
    private function getTokenAbilities(string $token): array
    {
        $plain = PersonalAccessToken::findToken($token);

        return $plain?->abilities ?? [];
    }

    public function test_user_role_token_carries_only_its_own_abilities(): void
    {
        // user role has only 'create_ticket' permission
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);

        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $abilities = $this->getTokenAbilities($token);

        $this->assertContains('notifications:read', $abilities);
        $this->assertContains('tickets:write', $abilities); // from create_ticket
        $this->assertNotContains('hardware:read', $abilities);
        $this->assertNotContains('reports:read', $abilities);
        $this->assertNotContains('persons:read', $abilities);
        $this->assertNotContains('units:read', $abilities);
        $this->assertNotContains('todos:read', $abilities);
        $this->assertNotContains('hr:read', $abilities);
        $this->assertNotContains('gis:read', $abilities);
        $this->assertNotContains('traffic:read', $abilities);
    }

    public function test_expert_role_token_includes_tickets_read(): void
    {
        // expert role has create_ticket + view_assigned_tickets
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket', 'view_assigned_tickets']);

        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $abilities = $this->getTokenAbilities($token);

        $this->assertContains('tickets:read', $abilities); // from view_assigned_tickets
        $this->assertContains('tickets:write', $abilities); // from create_ticket
        $this->assertNotContains('hardware:read', $abilities);
        $this->assertNotContains('reports:read', $abilities);
    }

    public function test_unit_manager_role_token_includes_reports_and_units(): void
    {
        // unit_manager has: create_ticket, manage_unit_tickets, view_assigned_tickets, organization, manage_personnel
        ['user' => $user] = $this->createUserWithUnit(
            permissions: ['create_ticket', 'manage_unit_tickets', 'view_assigned_tickets', 'organization', 'manage_personnel']
        );

        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $abilities = $this->getTokenAbilities($token);

        $this->assertContains('units:read', $abilities);
        $this->assertContains('units:write', $abilities);
        $this->assertContains('tickets:read', $abilities);
        $this->assertContains('tickets:write', $abilities);
        $this->assertContains('persons:read', $abilities); // from manage_personnel
        $this->assertContains('persons:write', $abilities); // from manage_personnel
        $this->assertContains('reports:read', $abilities); // from manage_personnel
        $this->assertNotContains('hardware:read', $abilities);
        $this->assertNotContains('hr:read', $abilities);
        $this->assertNotContains('gis:read', $abilities);
        $this->assertNotContains('traffic:read', $abilities);
    }

    public function test_admin_role_token_includes_all_abilities(): void
    {
        // admin gets all permissions via RoleSeeder::syncPermissions(Permission::all())
        ['user' => $user] = $this->createUserWithUnit(permissions: [
            'view_all_tickets', 'create_ticket', 'manage_unit_tickets',
            'calendar', 'view_hr_dashboard', 'map',
            'organization', 'manage_hardware', 'manage_personnel',
        ]);

        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $abilities = $this->getTokenAbilities($token);

        $expected = [
            'units:read', 'units:write',
            'hardware:read', 'hardware:write',
            'tickets:read', 'tickets:write',
            'persons:read', 'persons:write',
            'todos:read', 'todos:write',
            'hr:read',
            'notifications:read',
            'gis:read',
            'reports:read',
            'traffic:read',
        ];

        foreach ($expected as $ability) {
            $this->assertContains($ability, $abilities, "Missing ability: $ability");
        }
    }

    public function test_kargozini_permission_token_includes_persons_read(): void
    {
        // kargozini permission alone grants persons:read (not write, not reports)
        ['user' => $user] = $this->createUserWithUnit(permissions: ['kargozini']);

        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $abilities = $this->getTokenAbilities($token);

        $this->assertContains('persons:read', $abilities);
        $this->assertNotContains('persons:write', $abilities);
        $this->assertNotContains('reports:read', $abilities);
    }

    // ──────────────────────────────────────────────
    // New Spatie gates on read groups (Plan 003)
    // ──────────────────────────────────────────────

    public function test_user_role_cannot_access_reports_read(): void
    {
        // user role has only create_ticket → no manage_personnel → 403 on /api/reports/*
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/reports/units', $token)->assertForbidden();
        $this->apiGet('/api/reports/todos', $token)->assertForbidden();
        $this->apiGet('/api/reports/tickets', $token)->assertForbidden();
    }

    public function test_user_role_cannot_access_hardware_read(): void
    {
        // user role has only create_ticket → no manage_hardware → 403 on /api/hardware
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/hardware', $token)->assertForbidden();
        $this->apiGet('/api/hardware/stats', $token)->assertForbidden();
    }

    public function test_user_role_cannot_access_units_read(): void
    {
        // user role has only create_ticket → no organization → 403 on /api/units
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/units', $token)->assertForbidden();
    }

    public function test_user_role_cannot_access_zabbix_read(): void
    {
        // user role has only create_ticket → no map|bw → 403 on /api/zabbix/*
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        // Multi-latest requires item_ids — 422 means gate passed, validation ran next
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertEquals(403, $response->getStatusCode());

        $this->apiGet('/api/zabbix/traffic', $token)->assertForbidden();
    }

    public function test_user_role_cannot_access_persons_read(): void
    {
        // user role has only create_ticket → no kargozini|manage_personnel → 403 on /api/persons
        ['user' => $user] = $this->createUserWithUnit(permissions: ['create_ticket']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/persons', $token)->assertForbidden();
    }

    public function test_kargozini_permission_can_access_persons_read(): void
    {
        // kargozini permission grants persons:read + the Spatie gate kargozini|manage_personnel
        ['user' => $user] = $this->createUserWithUnit(permissions: ['kargozini']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->apiGet('/api/persons', $token)->assertOk();
    }

    public function test_map_permission_can_access_zabbix_read(): void
    {
        // map permission grants traffic:read + gis:read + the Spatie gate map|bw
        ['user' => $user] = $this->createUserWithUnit(permissions: ['map']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        // Multi-latest requires item_ids — 422 means gate passed, validation ran next
        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);

        // Traffic endpoint also requires item_ids — 422 means gate passed
        $response = $this->apiGet('/api/zabbix/traffic', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);
        $this->apiGet('/api/gis/stats', $token)->assertOk();
    }

    public function test_bw_permission_can_access_zabbix_read(): void
    {
        // bw permission also grants map|bw gate
        ['user' => $user] = $this->createUserWithUnit(permissions: ['bw']);
        $token = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $response = $this->apiGet('/api/zabbix/multi-latest', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);

        $response = $this->apiGet('/api/zabbix/traffic', $token);
        $this->assertContains($response->getStatusCode(), [200, 422]);
    }
}
