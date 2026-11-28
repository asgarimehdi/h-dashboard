<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UnitController;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Support\RoleGuard;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1649: the API must enforce the same allowed-parent-type rule the web
 * form does (`unit_type_relationships`), and a non-root unit must have a parent.
 *
 * Fixture types:
 *  1  وزارت بهداشت (root — no relationship rows, so it accepts no parent)
 *  2  دانشگاه علوم پزشکی → parent 1
 *  3  معاونت بهداشت      → parent 2
 *  4  شبکه بهداشت        → parent 3
 *  5  نوع بدون رابطه     → no rows at all
 *  22 واحد ستادی         → parent 22 (self-edge, as in the real seeder)
 */
covers(UnitController::class);

class ApiUnitParentTypeTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private Unit $root;

    private Unit $university;

    private Unit $deputy;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        DB::table('unit_types')->insert([
            ['id' => 1, 'name' => 'وزارت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'دانشگاه علوم پزشکی', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'معاونت بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'شبکه بهداشت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'نوع بدون رابطه', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'name' => 'واحد ستادی', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('unit_type_relationships')->insert([
            ['child_unit_type_id' => 2, 'allowed_parent_unit_type_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 3, 'allowed_parent_unit_type_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 4, 'allowed_parent_unit_type_id' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['child_unit_type_id' => 22, 'allowed_parent_unit_type_id' => 22, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->resyncSequence('unit_types');
        $this->resyncSequence('unit_type_relationships');

        $setup = $this->createUserWithUnit(['organization']);
        $user = $setup['user'];
        $this->user = $user;
        $this->root = $setup['unit'];
        $this->root->update(['unit_type_id' => UnitType::ROOT_ID]);

        $this->university = Unit::create([
            'name' => 'دانشگاه',
            'unit_type_id' => 2,
            'parent_id' => $this->root->id,
        ]);
        $this->deputy = Unit::create([
            'name' => 'معاونت',
            'unit_type_id' => 3,
            'parent_id' => $this->university->id,
        ]);

        $this->token = $this->createApiToken($user, ['units:read', 'units:write']);
    }

    /**
     * Issue #1864: creating/moving to the hierarchy root is admin-only.
     */
    private function promoteToAdmin(): void
    {
        Role::firstOrCreate(['name' => RoleGuard::ADMIN, 'guard_name' => 'web']);
        $this->user->assignRole(RoleGuard::ADMIN);
    }

    /**
     * A unit of type 4 (network) parented under a university (type 2), where
     * the only allowed parent is type 3.
     */
    private function network(): Unit
    {
        return Unit::create([
            'name' => 'شبکه',
            'unit_type_id' => 4,
            'parent_id' => $this->deputy->id,
        ]);
    }

    // ──────────────────────────────────────────────
    // store
    // ──────────────────────────────────────────────

    public function test_store_rejects_incompatible_parent_type(): void
    {
        $response = $this->apiPost('/api/units', [
            'name' => 'شبکه نامجاز',
            'unit_type_id' => 4,
            'parent_id' => $this->university->id,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Parent unit type is not allowed for this unit type.']);

        $this->assertDatabaseMissing('units', ['name' => 'شبکه نامجاز']);
    }

    public function test_store_accepts_allowed_parent_type(): void
    {
        $response = $this->apiPost('/api/units', [
            'name' => 'شبکه مجاز',
            'unit_type_id' => 4,
            'parent_id' => $this->deputy->id,
        ], $this->token);

        $response->assertCreated();

        $this->assertDatabaseHas('units', [
            'name' => 'شبکه مجاز',
            'unit_type_id' => 4,
            'parent_id' => $this->deputy->id,
        ]);
    }

    public function test_store_rejects_non_root_without_parent(): void
    {
        $response = $this->apiPost('/api/units', [
            'name' => 'بی‌والد',
            'unit_type_id' => 4,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'A parent unit is required for this unit type.']);

        $this->assertDatabaseMissing('units', ['name' => 'بی‌والد']);
    }

    public function test_admin_store_accepts_root_without_parent(): void
    {
        // Issue #1864: minting a root is admin-only.
        $this->promoteToAdmin();

        $response = $this->apiPost('/api/units', [
            'name' => 'وزارت دوم',
            'unit_type_id' => UnitType::ROOT_ID,
        ], $this->token);

        $response->assertCreated();

        $this->assertDatabaseHas('units', [
            'name' => 'وزارت دوم',
            'unit_type_id' => UnitType::ROOT_ID,
            'parent_id' => null,
        ]);
    }

    public function test_scoped_manager_cannot_create_root_without_parent(): void
    {
        // Issue #1864: the default actor here is a plain `organization` user.
        $response = $this->apiPost('/api/units', [
            'name' => 'ریشه غیرمجاز',
            'unit_type_id' => UnitType::ROOT_ID,
        ], $this->token);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only an administrator can create or move a unit to the hierarchy root.']);

        $this->assertDatabaseMissing('units', ['name' => 'ریشه غیرمجاز']);
    }

    public function test_store_rejects_root_with_parent(): void
    {
        // Type 1 has no relationship rows, so no parent type is allowed —
        // exactly what the web form already rejects.
        $response = $this->apiPost('/api/units', [
            'name' => 'ریشه زیرمجموعه',
            'unit_type_id' => UnitType::ROOT_ID,
            'parent_id' => $this->university->id,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Parent unit type is not allowed for this unit type.']);

        $this->assertDatabaseMissing('units', ['name' => 'ریشه زیرمجموعه']);
    }

    public function test_store_rejects_type_without_relationship_rows(): void
    {
        $withParent = $this->apiPost('/api/units', [
            'name' => 'نوع بی‌رابطه با والد',
            'unit_type_id' => 5,
            'parent_id' => $this->root->id,
        ], $this->token);

        $withParent->assertStatus(422)
            ->assertJson(['message' => 'Parent unit type is not allowed for this unit type.']);

        $withoutParent = $this->apiPost('/api/units', [
            'name' => 'نوع بی‌رابطه بی‌والد',
            'unit_type_id' => 5,
        ], $this->token);

        $withoutParent->assertStatus(422)
            ->assertJson(['message' => 'A parent unit is required for this unit type.']);

        $this->assertDatabaseMissing('units', ['name' => 'نوع بی‌رابطه با والد']);
        $this->assertDatabaseMissing('units', ['name' => 'نوع بی‌رابطه بی‌والد']);
    }

    public function test_store_accepts_self_edge_relationship(): void
    {
        // [22,22] is a real seeder self-edge: ستادی may sit under ستادی.
        $staff1 = Unit::create([
            'name' => 'ستاد ۱',
            'unit_type_id' => 22,
            'parent_id' => $this->root->id,
        ]);

        $response = $this->apiPost('/api/units', [
            'name' => 'ستاد ۲',
            'unit_type_id' => 22,
            'parent_id' => $staff1->id,
        ], $this->token);

        $response->assertCreated();

        $this->assertDatabaseHas('units', ['name' => 'ستاد ۲', 'parent_id' => $staff1->id]);
    }

    // ──────────────────────────────────────────────
    // update
    // ──────────────────────────────────────────────

    public function test_update_rejects_incompatible_reparent(): void
    {
        $network = $this->network();

        $response = $this->apiPut("/api/units/{$network->id}", [
            'parent_id' => $this->university->id,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Parent unit type is not allowed for this unit type.']);

        $this->assertSame($this->deputy->id, $network->fresh()->parent_id);
    }

    public function test_update_rejects_type_change_incompatible_with_current_parent(): void
    {
        $network = $this->network();

        $response = $this->apiPut("/api/units/{$network->id}", [
            'unit_type_id' => 2,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Parent unit type is not allowed for this unit type.']);

        $this->assertSame(4, $network->fresh()->unit_type_id);
    }

    public function test_update_accepts_allowed_reparent(): void
    {
        $network = $this->network();
        $deputy2 = Unit::create([
            'name' => 'معاونت دوم',
            'unit_type_id' => 3,
            'parent_id' => $this->university->id,
        ]);

        $response = $this->apiPut("/api/units/{$network->id}", [
            'parent_id' => $deputy2->id,
        ], $this->token);

        $response->assertOk();

        $this->assertSame($deputy2->id, $network->fresh()->parent_id);
    }

    public function test_update_accepts_compatible_type_and_parent_combination(): void
    {
        $network = $this->network();

        // Type 3 under a type-2 parent is allowed, even though the unit's old
        // combination (4 under 3) and its old type (4) would not be.
        $response = $this->apiPut("/api/units/{$network->id}", [
            'unit_type_id' => 3,
            'parent_id' => $this->university->id,
        ], $this->token);

        $response->assertOk();

        $network->refresh();
        $this->assertSame(3, $network->unit_type_id);
        $this->assertSame($this->university->id, $network->parent_id);
    }

    public function test_update_rejects_clearing_parent_on_non_root(): void
    {
        $network = $this->network();

        $response = $this->apiPut("/api/units/{$network->id}", [
            'parent_id' => null,
        ], $this->token);

        $response->assertStatus(422)
            ->assertJson(['message' => 'A parent unit is required for this unit type.']);

        $this->assertSame($this->deputy->id, $network->fresh()->parent_id);
    }

    public function test_admin_update_allows_clearing_parent_on_root(): void
    {
        // Set up directly: no write surface can create a root WITH a parent.
        $root2 = Unit::create([
            'name' => 'ریشه دوم',
            'unit_type_id' => UnitType::ROOT_ID,
            'parent_id' => $this->root->id,
        ]);

        // Issue #1864: moving a unit to the root is admin-only.
        $this->promoteToAdmin();

        $response = $this->apiPut("/api/units/{$root2->id}", [
            'parent_id' => null,
        ], $this->token);

        $response->assertOk();

        $this->assertNull($root2->fresh()->parent_id);
    }

    public function test_update_name_only_skips_type_rule_on_typeless_legacy_unit(): void
    {
        // A null unit_type_id only exists on legacy/factory rows; the type rule
        // is not defined for it, so a name-only edit must stay possible.
        $legacy = Unit::create([
            'name' => 'قدیمی',
            'parent_id' => $this->root->id,
        ]);

        $response = $this->apiPut("/api/units/{$legacy->id}", [
            'name' => 'قدیمی ۲',
        ], $this->token);

        $response->assertOk();

        $legacy->refresh();
        $this->assertSame('قدیمی ۲', $legacy->name);
        $this->assertNull($legacy->unit_type_id);
    }
}
