<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonModelTest extends TestCase
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
    }

    protected function createPerson(array $attrs = []): Person
    {
        $unit = Unit::firstOrCreate(['name' => 'واحد تست']);
        return Person::create(array_merge([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'f_name' => 'علی',
            'l_name' => 'احمدی',
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unit->id,
        ], $attrs));
    }

    // --- Route key ---

    public function test_person_uses_n_code_as_route_key(): void
    {
        $person = $this->createPerson();

        $this->assertEquals('n_code', $person->getRouteKeyName());
    }

    // --- Relationships ---

    public function test_person_has_one_user(): void
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::firstOrCreate(['name' => 'واحد تست']);
        $person = Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->assertNotNull($person->user);
        $this->assertEquals($user->id, $person->user->id);
    }

    public function test_person_belongs_to_unit(): void
    {
        $unit = Unit::create(['name' => 'واحد اختصاصی']);
        $person = $this->createPerson(['u_id' => $unit->id]);

        $this->assertNotNull($person->unit);
        $this->assertEquals($unit->id, $person->unit->id);
    }

    public function test_person_belongs_to_estekhdam(): void
    {
        $person = $this->createPerson();

        $this->assertNotNull($person->estekhdam);
        $this->assertEquals(1, $person->estekhdam->id);
    }

    public function test_person_belongs_to_tahsil(): void
    {
        $person = $this->createPerson();

        $this->assertNotNull($person->tahsil);
        $this->assertEquals(1, $person->tahsil->id);
    }

    public function test_person_belongs_to_semata(): void
    {
        $person = $this->createPerson();

        $this->assertNotNull($person->semat);
        $this->assertEquals(1, $person->semat->id);
    }

    public function test_person_belongs_to_radif(): void
    {
        $person = $this->createPerson();

        $this->assertNotNull($person->radif);
        $this->assertEquals(1, $person->radif->id);
    }

    // --- Name accessor ---

    public function test_person_name_accessor_returns_full_name(): void
    {
        $person = $this->createPerson(['f_name' => 'محمد', 'l_name' => 'رضایی']);

        $this->assertEquals('محمد رضایی', $person->name);
    }

    // --- Persian normalization on save ---

    public function test_person_normalizes_f_name_on_save(): void
    {
        $person = $this->createPerson();
        $person->update(['f_name' => 'علي']); // ALEF-MADDA → should stay but YEH/KAF normalize

        $person->refresh();
        // Arabic Yeh (ي) → Persian Yeh (ی)
        $this->assertNotEquals('علي', $person->f_name);
    }

    public function test_person_normalizes_l_name_on_save(): void
    {
        $person = $this->createPerson();
        $person->update(['l_name' => 'احمدى\u{200C}پور']); // ZWNJ should be normalized to space

        $person->refresh();
        $this->assertStringNotContainsString("\u{200C}", $person->l_name);
    }

    // --- Cache invalidation on save ---

    public function test_person_save_increments_hr_stats_version(): void
    {
        Cache::put('hr_stats_version', 0);
        $person = $this->createPerson();

        $this->assertGreaterThan(0, Cache::get('hr_stats_version'));
    }

    public function test_person_save_increments_dashboard_version(): void
    {
        Cache::put('dashboard_version', 0);
        $person = $this->createPerson();

        $this->assertGreaterThan(0, Cache::get('dashboard_version'));
    }

    public function test_person_save_increments_maps_version(): void
    {
        Cache::put('maps_version', 0);
        $person = $this->createPerson();

        $this->assertGreaterThan(0, Cache::get('maps_version'));
    }

    public function test_person_delete_increments_hr_stats_version(): void
    {
        $person = $this->createPerson();
        Cache::put('hr_stats_version', 0);

        $person->delete();

        $this->assertGreaterThan(0, Cache::get('hr_stats_version'));
    }

    // --- Fillable ---

    public function test_person_allows_mass_assignment(): void
    {
        $person = $this->createPerson();

        $this->assertEquals('علی', $person->f_name);
        $this->assertEquals('احمدی', $person->l_name);
    }
}
