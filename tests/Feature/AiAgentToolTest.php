<?php

namespace Tests\Feature;

use App\Ai\Tools\Hardware\CreateHardwareTool;
use App\Ai\Tools\Hardware\DeleteHardwareTool;
use App\Ai\Tools\Hardware\HardwareStatsTool;
use App\Ai\Tools\Hardware\PersonHardwareTool;
use App\Ai\Tools\Hardware\SearchHardwareTool;
use App\Ai\Tools\Hardware\UpdateHardwareTool;
use App\Ai\Tools\SearchPersonsTool;
use App\Ai\Tools\SearchUnitsTool;
use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AiAgentToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    /**
     * Create a user with a unit. Returns the user, unit, and the n_code used.
     * The person created has u_id = unit->id (so it's in the correct unit).
     */
    protected function createUserWithUnit(string $unitName = 'Test Unit'): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $unit = Unit::create(['name' => $unitName]);

        $nCode = (string) rand(1000000000, 9999999999);
        $person = Person::create([
            'n_code' => $nCode,
            'f_name' => 'T',
            'l_name' => 'U',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unit->id,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit, 'n_code' => $nCode, 'person' => $person];
    }

    public function test_search_hardware_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Hardware in Unit A
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-001', 'type' => 'desktop']);
        // Hardware in Unit B
        Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B-001', 'type' => 'laptop']);

        // Switch to User A's session
        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new SearchHardwareTool();
        $results = $tool->execute(['query' => 'PC-A']);

        // Should find PC-A-001
        $this->assertNotEmpty($results);
        $this->assertEquals('PC-A-001', $results[0]['pc_name']);

        $resultsB = $tool->execute(['query' => 'PC-B']);
        // Should NOT find PC-B-001 (different unit)
        $this->assertEquals("No results for \"PC-B\" within your access scope.", $resultsB);
    }

    public function test_hardware_stats_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Hardware in Unit A (2 desktop, 1 shutdown)
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-001', 'type' => 'desktop', 'shutdown' => false]);
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-002', 'type' => 'desktop', 'shutdown' => false]);
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-003', 'type' => 'laptop', 'shutdown' => true]);
        // Hardware in Unit B (1 server)
        Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B-001', 'type' => 'server', 'shutdown' => false]);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new HardwareStatsTool();
        $stats = $tool->execute(['category' => 'overview']);

        // Should only count Unit A's hardware (3 total, 1 shutdown)
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['shutdown']);
        $this->assertEquals(2, $stats['by_type']['desktop'] ?? 0);
        $this->assertEquals(1, $stats['by_type']['laptop'] ?? 0);
        // Should NOT count Unit B's server
        $this->assertArrayNotHasKey('server', $stats['by_type']);
    }

    public function test_person_hardware_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Hardware for both persons
        Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-001', 'type' => 'desktop']);
        Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B-001', 'type' => 'laptop']);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new PersonHardwareTool();

        // Should find Person A's hardware
        $resultA = $tool->execute(['n_code' => $personA->n_code]);
        $this->assertIsArray($resultA);
        $this->assertEquals($personA->f_name . ' ' . $personA->l_name, $resultA['owner']);
        $this->assertCount(1, $resultA['devices']);

        // Should NOT find Person B's hardware (different unit)
        $resultB = $tool->execute(['n_code' => $personB->n_code]);
        $this->assertEquals("No hardware found for n_code {$personB->n_code} within your access scope.", $resultB);
    }

    public function test_update_hardware_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Hardware in Unit A
        $hwA = Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-001', 'cpu' => 'Intel i3']);
        // Hardware in Unit B
        $hwB = Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B-001', 'cpu' => 'AMD Ryzen']);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new UpdateHardwareTool();

        // Should update Unit A's hardware
        $resultA = $tool->execute(['id' => $hwA->id, 'cpu' => 'Intel i7']);
        $this->assertStringContainsString('Updated', $resultA);
        $this->assertEquals('Intel i7', Hardware::find($hwA->id)->cpu);

        // Should NOT update Unit B's hardware
        $resultB = $tool->execute(['id' => $hwB->id, 'cpu' => 'Intel i9']);
        $this->assertEquals("Hardware #{$hwB->id} not found or access denied.", $resultB);
        $this->assertEquals('AMD Ryzen', Hardware::find($hwB->id)->cpu);
    }

    public function test_delete_hardware_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Hardware in Unit A
        $hwA = Hardware::create(['n_code' => $personA->n_code, 'pc_name' => 'PC-A-001']);
        // Hardware in Unit B
        $hwB = Hardware::create(['n_code' => $personB->n_code, 'pc_name' => 'PC-B-001']);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new DeleteHardwareTool();

        // Should delete Unit A's hardware
        $resultA = $tool->execute(['id' => $hwA->id, 'confirm' => true]);
        $this->assertStringContainsString('Deleted', $resultA);
        $this->assertDatabaseMissing('hardwares', ['id' => $hwA->id]);

        // Should NOT delete Unit B's hardware
        $resultB = $tool->execute(['id' => $hwB->id, 'confirm' => true]);
        $this->assertEquals("Hardware #{$hwB->id} not found or access denied.", $resultB);
        $this->assertDatabaseHas('hardwares', ['id' => $hwB->id]);
    }

    public function test_create_hardware_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new CreateHardwareTool();

        // Should create hardware for Person A (same unit)
        $resultA = $tool->execute(['n_code' => $personA->n_code, 'pc_name' => 'NEW-PC-A']);
        $this->assertStringContainsString('Successfully created', $resultA);
        $this->assertDatabaseHas('hardwares', ['n_code' => $personA->n_code, 'pc_name' => 'NEW-PC-A']);

        // Should NOT create hardware for Person B (different unit)
        $resultB = $tool->execute(['n_code' => $personB->n_code, 'pc_name' => 'NEW-PC-B']);
        $this->assertStringNotContainsString('Successfully created', $resultB);
        $this->assertStringContainsString('not found or not within your organizational scope', $resultB);
    }

    public function test_search_persons_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA, 'person' => $personA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB, 'person' => $personB] = $this->createUserWithUnit('Unit B');

        // Create additional persons in Unit A
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);
        
        $personA2 = Person::create([
            'n_code' => (string) rand(1000000000, 9999999999),
            'f_name' => 'Ahmed',
            'l_name' => 'Rezaei',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unitA->id,
        ]);

        // Create person in Unit B
        $personB2 = Person::create([
            'n_code' => (string) rand(1000000000, 9999999999),
            'f_name' => 'Mohammad',
            'l_name' => 'Hosseini',
            't_id' => $tId,
            'e_id' => $eId,
            's_id' => $sId,
            'r_id' => $rId,
            'u_id' => $unitB->id,
        ]);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new SearchPersonsTool();

        // Should find persons in Unit A
        $resultsA = $tool->execute(['query' => 'Ahmed']);
        $this->assertIsArray($resultsA);
        $this->assertNotEmpty($resultsA);
        $this->assertEquals('Ahmed Rezaei', $resultsA[0]['name']);

        // Should NOT find persons in Unit B
        $resultsB = $tool->execute(['query' => 'Mohammad']);
        $this->assertEquals('No results for "Mohammad" within your access scope.', $resultsB);
    }

    public function test_search_units_tool_respects_organizational_scope(): void
    {
        // User A in Unit A
        ['user' => $userA, 'unit' => $unitA] = $this->createUserWithUnit('Unit A');

        // User B in Unit B
        ['user' => $userB, 'unit' => $unitB] = $this->createUserWithUnit('Unit B');

        // Create sub-unit under Unit A
        $subUnitA = Unit::create(['name' => 'Unit A Sub', 'parent_id' => $unitA->id]);

        // Create sub-unit under Unit B
        $subUnitB = Unit::create(['name' => 'Unit B Sub', 'parent_id' => $unitB->id]);

        Session::put('current_unit_id', $unitA->id);
        $this->actingAs($userA, 'sanctum');

        $tool = new SearchUnitsTool();

        // Should find Unit A and its sub-unit
        $resultsA = $tool->execute(['query' => 'Unit A']);
        $this->assertIsArray($resultsA);
        $this->assertNotEmpty($resultsA);
        $names = array_column($resultsA, 'name');
        $this->assertContains('Unit A', $names);
        $this->assertContains('Unit A Sub', $names);

        // Should NOT find Unit B or its sub-unit
        $resultsB = $tool->execute(['query' => 'Unit B']);
        $this->assertEquals('No results for "Unit B" within your access scope.', $resultsB);
    }
}
