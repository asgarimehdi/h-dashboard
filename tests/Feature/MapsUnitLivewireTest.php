<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class MapsUnitLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function seedRegionAndTypes(): Region
    {
        $region = Region::create(['name' => 'شهرستان تست', 'type' => 'county']);

        // UnitType::$fillable excludes 'id'; use DB::table for explicit IDs
        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'مرکز خدمات جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'پایگاه سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::select("SELECT setval('unit_types_id_seq', GREATEST((SELECT COALESCE(MAX(id),0) FROM unit_types), 1))");

        return $region;
    }

    protected function createBoundary(): int
    {
        DB::statement(
            'INSERT INTO boundaries (boundary, created_at, updated_at) VALUES '
            ."(ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326), now(), now())"
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    // ==================== Auth / permissions ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/unit')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        $this->get('/maps/unit')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertStatus(200)
            ->assertSee('نقشه واحد ها');
    }

    // ==================== Unit boundary / map display ====================

    public function test_unit_boundary(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        // Initially: region toggles are visible; center types only after selection
        Livewire::test('maps/unit')
            ->assertSee('شهرستان تست')
            ->assertDontSee('مرکز جامع سلامت')  // hidden until region selected
            ->set('selectedRegions', [$region->id])
            ->assertSee('مرکز جامع سلامت')       // now visible
            ->assertSee('مرکز خدمات جامع سلامت')
            ->assertSee('پایگاه سلامت');
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();
        $boundaryId = $this->createBoundary();

        // Accessible unit in selected region with a boundary (required by component)
        $accessibleUnit = Unit::create([
            'name' => 'واحد قابل دسترس',
            'region_id' => $region->id,
            'unit_type_id' => 5,
            'boundary_id' => $boundaryId,
        ]);

        session(['current_unit_id' => $accessibleUnit->id]);

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحد قابل دسترس');
    }

    // ==================== Filters ====================

    public function test_search_filter(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->set('search', 'test')
            ->assertStatus(200);
    }

    public function test_region_filter_dispatches_events(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = $this->seedRegionAndTypes();

        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->assertDispatched('county-boundaries-loaded')
            ->assertDispatched('units-updated');
    }

    // ==================== Edge cases ====================

    public function test_empty_state_without_units(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        $region = Region::create(['name' => 'شهرستان خالی', 'type' => 'county']);

        DB::table('unit_types')->insert([
            ['id' => 5, 'name' => 'مرکز جامع سلامت', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Select region + center type but no matching units exist
        Livewire::test('maps/unit')
            ->set('selectedRegions', [$region->id])
            ->set('selectedCenterTypes', [5])
            ->assertSee('واحدی یافت نشد');
    }

    public function test_show_help_modal(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);

        Livewire::test('maps/unit')
            ->assertSet('showHelpModal', false)
            ->call('$refresh')
            ->assertStatus(200);
    }
}
