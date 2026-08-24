<?php

namespace Tests\Feature;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupSimpleModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Explicit-id inserts do not advance Postgres sequences, so a later
        // auto-increment create would collide with id=1 (sequences survive
        // RefreshDatabase's per-test rollback). Sync them past max(id).
        foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
            DB::unprepared(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT MAX(id) FROM {$table}))"
            );
        }
    }

    // ==================== Semat ====================

    public function test_semat_allows_mass_assignment(): void
    {
        $semat = Semat::create(['name' => 'مدیر']);

        $this->assertEquals('مدیر', $semat->name);
    }

    public function test_semat_has_many_persons(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $semat = Semat::create(['name' => 'مدیر']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => $semat->id, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        $this->assertCount(1, $semat->person);
    }

    // ==================== Tahsil ====================

    public function test_tahsil_allows_mass_assignment(): void
    {
        $tahsil = Tahsil::create(['name' => 'کارشناسی']);

        $this->assertEquals('کارشناسی', $tahsil->name);
    }

    public function test_tahsil_has_many_persons(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $tahsil = Tahsil::create(['name' => 'کارشناسی']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => $tahsil->id, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        $this->assertCount(1, $tahsil->person);
    }

    // ==================== Estekhdam ====================

    public function test_estekhdam_allows_mass_assignment(): void
    {
        $est = Estekhdam::create(['name' => 'رسمی']);

        $this->assertEquals('رسمی', $est->name);
    }

    public function test_estekhdam_has_many_persons(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $est = Estekhdam::create(['name' => 'رسمی']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => $est->id, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);

        $this->assertCount(1, $est->person);
    }

    // ==================== Radif ====================

    public function test_radif_allows_mass_assignment(): void
    {
        $radif = Radif::create(['name' => 'رتبه ۱']);

        $this->assertEquals('رتبه ۱', $radif->name);
    }

    public function test_radif_has_many_persons(): void
    {
        $unit = Unit::create(['name' => 'واحد']);
        $radif = Radif::create(['name' => 'رتبه ۱']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => $radif->id, 'u_id' => $unit->id,
        ]);

        $this->assertCount(1, $radif->person);
    }
}
