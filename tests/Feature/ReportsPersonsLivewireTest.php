<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsPersonsLivewireTest extends TestCase
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

        // Resync sequences so later auto-incremented inserts don't collide
        DB::select("SELECT setval('tahsils_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM tahsils), 1), 1))");
        DB::select("SELECT setval('estekhdams_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM estekhdams), 1), 1))");
        DB::select("SELECT setval('semats_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM semats), 1), 1))");
        DB::select("SELECT setval('radifs_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM radifs), 1), 1))");
        DB::select("SELECT setval('units_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM units), 1), 1))");
        DB::select("SELECT setval('persons_id_seq', GREATEST(COALESCE((SELECT MAX(id) FROM persons), 1), 1))");
    }

    protected function createUserWithUnit(string $permission = ''): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        if ($permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    protected function actingWithUnit(User $user): void
    {
        $unit = $user->units()->first();
        $this->actingAs($user);
        session(['current_unit_id' => $unit->id]);
    }

    protected function createPersonInUnit(Unit $unit, string $fName = 'شخص', string $lName = 'تست'): Person
    {
        $nCode = (string) fake()->unique()->numerify('##########');

        return Person::create([
            'n_code' => $nCode, 'f_name' => $fName, 'l_name' => $lName,
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
    }

    // ==================== Smoke / auth tests ====================

    public function test_guest_302(): void
    {
        $this->get('/reports/persons')->assertRedirect('/login');
    }

    public function test_no_context_redirect(): void
    {
        // User with 2 units and no current_unit_id → ValidateUnitContext redirects
        $unit1 = Unit::create(['name' => 'واحد ۱']);
        $unit2 = Unit::create(['name' => 'واحد ۲']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'دوواحدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit1->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit1->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unit2->id, ['role' => 'staff', 'is_primary' => false]);

        $this->actingAs($user);
        $this->get('/reports/persons')->assertRedirect('/select-context');
    }

    public function test_renders_stats(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        Livewire::test('reports.persons')
            ->assertStatus(200)
            ->assertSee('گزارش پرسنل')
            ->assertSee('کل پرسنل');
    }

    // ==================== Count match ====================

    public function test_counts_match(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingWithUnit($user);
        $unit = $user->units()->first();

        // Seed 2 more persons into this unit (setUp already created 1 via createUserWithUnit)
        $this->createPersonInUnit($unit, 'علی', 'اول');
        $this->createPersonInUnit($unit, 'رضا', 'دوم');

        // Total: setUp person + ali + reza = 3
        Livewire::test('reports.persons')
            ->assertSee('تست')     // setUp person's f_name
            ->assertSee('علی')    // ali
            ->assertSee('رضا')    // reza
            ->assertSeeHtml('3');  // total count displayed in stat card
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        // Create parent unit and child unit
        $parent = Unit::create(['name' => 'والد']);
        $child = Unit::create(['name' => 'فرزند', 'parent_id' => $parent->id]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'در والد', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $parent->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($parent->id, ['role' => 'staff', 'is_primary' => true]);

        $this->createPersonInUnit($child, 'در فرزند', 'کاربر');

        // Person outside scope
        $outside = Unit::create(['name' => 'خارج']);
        $this->createPersonInUnit($outside, 'خارج', 'کاربر');

        $this->actingAs($user);
        session(['current_unit_id' => $parent->id]);

        app(AccessService::class)->clearCache($user);
        Cache::flush();

        Livewire::test('reports.persons')
            ->assertSee('در والد')
            ->assertSee('در فرزند')
            ->assertDontSee('خارج');
    }

    // ==================== Edge cases ====================

    public function test_empty_accessible_ids_renders_without_error(): void
    {
        // Person with null u_id, user with no units → accessibleUnitIds = []
        // Component should render without error (no filter applied)
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون واحد', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->actingAs($user);

        Livewire::test('reports.persons')
            ->assertStatus(200);
    }

    public function test_zero_persons_shows_empty_table(): void
    {
        // User attached to unitA; their person is in unitA.
        // Set current_unit_id to unitB which has zero persons.
        $unitA = Unit::create(['name' => 'واحد الف']);
        $unitB = Unit::create(['name' => 'واحد ب']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'الف', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitA->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);
        $user->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => false]);

        $this->actingAs($user);
        session(['current_unit_id' => $unitB->id]);

        Livewire::test('reports.persons')
            ->assertSee('موردی یافت نشد');
    }

    public function test_units_outside_scope_excluded(): void
    {
        // User in unit A; unit B is outside scope (not a descendant)
        $unitA = Unit::create(['name' => 'واحد الف']);
        $unitB = Unit::create(['name' => 'واحد ب']);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'الف', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unitA->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);

        $this->createPersonInUnit($unitB, 'ب', 'کاربر');

        $this->actingAs($user);
        session(['current_unit_id' => $unitA->id]);

        app(AccessService::class)->clearCache($user);
        Cache::flush();

        Livewire::test('reports.persons')
            ->assertSee('الف')
            ->assertDontSeeHtml('>ب<');
    }
}
