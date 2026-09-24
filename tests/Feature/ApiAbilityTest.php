<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
        $token = $this->createTokenWithAbilities(['units:read']);
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
        $token = $this->createTokenWithAbilities(['hardware:read']);
        $this->apiGet('/api/hardware', $token)->assertOk();
    }

    public function test_hardware_write_denied_without_ability(): void
    {
        $token = $this->createTokenWithAbilities(['hardware:read'], ['manage_hardware']);
        $this->apiPost('/api/hardware', ['n_code' => '123', 'pc_name' => 'test'], $token)->assertForbidden();
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
        $token = $this->createTokenWithAbilities(['persons:read']);
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
        $token = $this->createTokenWithAbilities(['reports:read']);
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
        $token = $this->createTokenWithAbilities(['traffic:read']);
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
}
