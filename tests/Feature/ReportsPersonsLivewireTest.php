<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class ReportsPersonsLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
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
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        Livewire::test('reports.persons')
            ->assertStatus(200)
            ->assertSee('گزارش پرسنل')
            ->assertSee('کل پرسنل');
    }

    // ==================== Count match ====================

    public function test_counts_match(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        // Seed 2 more persons into this unit (createUserWithUnit already created 1)
        $this->createPersonInUnit($unit, 'علی', 'اول');
        $this->createPersonInUnit($unit, 'رضا', 'دوم');

        // Total: createUserWithUnit person + ali + reza = 3
        Livewire::test('reports.persons')
            ->assertSee($user->person->f_name) // createUserWithUnit person's actual (factory) name
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

    public function test_empty_accessible_ids_scopes_to_zero_rows(): void
    {
        // Person with null u_id, user with no units → accessibleUnitIds = []
        // Issue #819: an empty scope must FAIL CLOSED — the roster is scoped to
        // nothing, so an out-of-scope person's n_code never renders. This used
        // to assert only assertStatus(200), which passed *because* of the leak.
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون واحد', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        // A whole other unit's person, which an empty-scope viewer must not see.
        $outside = Unit::create(['name' => 'خارج']);
        $foreignNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $foreignNCode, 'f_name' => 'خارجی', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $outside->id,
        ]);

        $this->actingAs($user);

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds(), 'the fixture must really be an empty scope');

        $component = Livewire::test('reports.persons')->assertStatus(200);

        $component->assertDontSee($foreignNCode)
            ->assertDontSee('خارجی');

        $this->assertSame(0, $component->instance()->chartPayload()['total']);
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

    // ==================== Issue #858: N+1 guard ====================

    // Separate methods: assertNoNPlusOne registers a global DB listener that
    // is never removed, so two measurements in one test would accumulate.
    //
    // Measured fixed cost is 17 queries (3 mount lookups + scope CTE + count
    // + 4 aggregates + roster fetch + 4 eager loads + 2 layout notification
    // queries + accessible-units); budget 20 leaves headroom while any
    // per-person scaling (4 lazy queries x persons) still blows the budget.
    public function test_roster_small_query_budget(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 4; $i++) {
            $this->createPersonInUnit($unit);
        }

        $this->assertNoNPlusOne(fn () => Livewire::test('reports.persons'), 20);
    }

    public function test_roster_large_same_query_budget(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 19; $i++) {
            $this->createPersonInUnit($unit);
        }

        $this->assertNoNPlusOne(fn () => Livewire::test('reports.persons'), 20);
    }

    public function test_chart_data_matches_server_aggregates(): void
    {
        // Issue #858 step 2: charts read the server-rendered data-chart div
        // (morphed on every render) instead of a second method request, so
        // the div must carry exactly the inline-@php aggregates — and not
        // the roster (charts never read it; ~53KB saved per update).
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingWithUnit($user);

        for ($i = 0; $i < 9; $i++) {
            $this->createPersonInUnit($unit);
        }

        $component = Livewire::test('reports.persons')->assertStatus(200);
        $payload = $component->instance()->chartPayload();
        $html = $component->html();

        $this->assertStringContainsString('id="personsChartData"', $html);
        $this->assertStringNotContainsString('chartPayload', $html);

        preg_match('/data-chart=\'([^\']*)\'/', $html, $m);
        $this->assertNotEmpty($m, 'data-chart attribute missing');
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        $this->assertSame($payload['byTahsil'], $decoded['byTahsil']);
        $this->assertSame($payload['bySemat'], $decoded['bySemat']);
        $this->assertSame($payload['byEstekhdam'], $decoded['byEstekhdam']);
        $this->assertArrayNotHasKey('persons', $decoded);
    }
}
