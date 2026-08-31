<?php

namespace Tests\Feature;

use App\Models\Boundary;
use App\Models\Province;
use App\Models\Region;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\UnitTypeRelationship;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

#[CoversClass(\App\Models\Person::class)]

class LookupModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    // ==================== Region ====================

    public function test_region_has_many_children(): void
    {
        $province = Region::create(['name' => 'استان تست', 'type' => 'province']);
        $county1 = Region::create(['name' => 'شهرستان ۱', 'type' => 'county', 'parent_id' => $province->id]);
        $county2 = Region::create(['name' => 'شهرستان ۲', 'type' => 'county', 'parent_id' => $province->id]);

        $this->assertCount(2, $province->children);
    }

    public function test_region_belongs_to_parent(): void
    {
        $province = Region::create(['name' => 'استان', 'type' => 'province']);
        $county = Region::create(['name' => 'شهرستان', 'type' => 'county', 'parent_id' => $province->id]);

        $this->assertNotNull($county->parent);
        $this->assertEquals($province->id, $county->parent->id);
    }

    public function test_region_has_many_units(): void
    {
        $region = Region::create(['name' => 'استان تست', 'type' => 'province']);
        Unit::create(['name' => 'واحد ۱', 'region_id' => $region->id]);
        Unit::create(['name' => 'واحد ۲', 'region_id' => $region->id]);

        $this->assertCount(2, $region->units);
    }

    public function test_region_belongs_to_boundary(): void
    {
        $boundary = Boundary::create([
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
        ]);
        $region = Region::create([
            'name' => 'استان با مرز',
            'type' => 'province',
            'boundary_id' => $boundary->id,
        ]);

        $this->assertNotNull($region->boundary);
        $this->assertEquals($boundary->id, $region->boundary->id);
    }

    public function test_region_fillable(): void
    {
        $region = Region::create([
            'name' => 'تست',
            'type' => 'province',
            'parent_id' => null,
            'boundary_id' => null,
        ]);

        $this->assertEquals('تست', $region->name);
        $this->assertEquals('province', $region->type);
    }

    // ==================== Boundary ====================

    public function test_boundary_belongs_to_unit(): void
    {
        $boundary = Boundary::create([
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
        ]);
        Unit::create(['name' => 'واحد با مرز', 'boundary_id' => $boundary->id]);

        $this->assertNotNull($boundary->unit);
        $this->assertEquals('واحد با مرز', $boundary->unit->name);
    }

    public function test_boundary_allows_mass_assignment(): void
    {
        $boundary = Boundary::create([
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0, 1 0, 1 1, 0 1, 0 0)))', 4326)"),
        ]);

        $this->assertNotNull($boundary->id);
    }

    // ==================== UnitType ====================

    public function test_unit_type_has_allowed_parent_types(): void
    {
        $hospital = UnitType::create(['name' => 'بیمارستان']);
        $district = UnitType::create(['name' => 'شهرستان']);
        $province = UnitType::create(['name' => 'استان']);

        UnitTypeRelationship::create([
            'child_unit_type_id' => $hospital->id,
            'allowed_parent_unit_type_id' => $district->id,
        ]);

        $this->assertCount(1, $hospital->allowedParentTypes);
        $this->assertEquals($district->id, $hospital->allowedParentTypes->first()->id);
    }

    public function test_unit_type_fillable(): void
    {
        $type = UnitType::create(['name' => 'مرکز بهداشت', 'description' => 'توضیحات']);

        $this->assertEquals('مرکز بهداشت', $type->name);
        $this->assertEquals('توضیحات', $type->description);
    }

    // ==================== UnitTypeRelationship ====================

    public function test_relationship_belongs_to_child_type(): void
    {
        $child = UnitType::create(['name' => 'فرزند']);
        $parent = UnitType::create(['name' => 'والد']);

        $rel = UnitTypeRelationship::create([
            'child_unit_type_id' => $child->id,
            'allowed_parent_unit_type_id' => $parent->id,
        ]);

        $this->assertEquals($child->id, $rel->childUnitType->id);
    }

    public function test_relationship_belongs_to_parent_type(): void
    {
        $child = UnitType::create(['name' => 'فرزند']);
        $parent = UnitType::create(['name' => 'والد']);

        $rel = UnitTypeRelationship::create([
            'child_unit_type_id' => $child->id,
            'allowed_parent_unit_type_id' => $parent->id,
        ]);

        $this->assertEquals($parent->id, $rel->allowedParentUnitType->id);
    }

    // ==================== Province ====================

    // Province table is not migrated in test DB (optional), skip Province tests
}
