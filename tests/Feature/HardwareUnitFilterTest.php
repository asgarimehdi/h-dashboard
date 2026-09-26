<?php

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Unit;
use App\Services\AccessService;
use App\Traits\PersianNormalizer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #705 — the Persian `filterUnit` LIKE search.
 *
 * `normalizeForQuery()` maps ZWNJ (U+200C) to a plain space, which is correct
 * for user input but means the search term can never match the stored name: the
 * database keeps the ZWNJ. So a unit called "واحد بهداشت حرفه‌ای 39" is invisible
 * to its own name filter, and the row disappears from the table.
 *
 * It only surfaces when a test draws a ZWNJ-bearing name, which is why it looked
 * random: 14 of the 15 factory names contain no ZWNJ, so most runs pass.
 */
class HardwareUnitFilterTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createHardwareForUser($user, Unit $unit, array $overrides = []): Hardware
    {
        $person = Person::where('n_code', $user->n_code)->first();

        return Hardware::create(array_merge([
            'n_code' => $person->n_code,
            'pc_name' => 'PC-Test-'.fake()->unique()->bothify('####'),
            'type' => 'PC',
            'os' => 'Windows 10',
            'cpu' => 'Intel i5',
            'ram' => '8GB',
            'hdd' => '256GB SSD',
        ], $overrides));
    }

    protected function seedUnitWithHardware(string $unitName, string $pcName): array
    {
        $data = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        // Force the exact name under test so this never depends on faker.
        $data['unit']->update(['name' => $unitName]);

        Person::create([
            'n_code' => (string) fake()->unique()->numerify('##########'),
            'f_name' => 'دوم', 'l_name' => 'کاربر',
            't_id' => DB::table('tahsils')->first()->id,
            'e_id' => DB::table('estekhdams')->first()->id,
            's_id' => DB::table('semats')->first()->id,
            'r_id' => DB::table('radifs')->first()->id,
            'u_id' => $data['unit']->id,
        ]);

        $this->createHardwareForUser($data['user'], $data['unit'], ['pc_name' => $pcName]);

        return $data;
    }

    public function test_zwnj_in_the_query_term_breaks_the_like_match(): void
    {
        // "حرفه‌ای" contains ZWNJ (U+200C). normalizeForQuery turns it into a
        // space, so the term no longer matches the stored name.
        $name = 'واحد بهداشت حرفه‌ای 39';
        $term = PersianNormalizer::normalizeForQuery($name);

        $this->assertStringContainsString("\u{200C}", $name, 'fixture must contain ZWNJ');
        $this->assertStringNotContainsString("\u{200C}", $term, 'normalizeForQuery must have converted it');

        Unit::create(['name' => $name]);

        $like = Unit::where('name', 'LIKE', "%{$term}%")->count();
        $this->assertSame(0, $like, 'a plain LIKE can never match: the term lost the ZWNJ');
    }

    public function test_filter_unit_finds_a_zwnj_unit_by_its_own_name(): void
    {
        $data = $this->seedUnitWithHardware('واحد بهداشت حرفه‌ای 39', 'ZwnjPC');

        Livewire::test('hardware.index')
            ->set('filterUnit', $data['unit']->name)
            ->assertSee('ZwnjPC');
    }

    public function test_filter_unit_finds_a_plain_space_unit_by_its_own_name(): void
    {
        // The control case: no ZWNJ, so this must keep working.
        $data = $this->seedUnitWithHardware('واحد بهداشت حرفه ای 40', 'SpacePC');

        Livewire::test('hardware.index')
            ->set('filterUnit', $data['unit']->name)
            ->assertSee('SpacePC');
    }

    public function test_filter_unit_matches_when_the_user_omits_the_zwnj(): void
    {
        // A user typing on a Persian keyboard often cannot produce ZWNJ, so the
        // filter must still find "حرفه‌ای" when they type "حرفه ای".
        $data = $this->seedUnitWithHardware('واحد بهداشت حرفه‌ای 41', 'TypedSpacePC');

        Livewire::test('hardware.index')
            ->set('filterUnit', 'واحد بهداشت حرفه ای 41')
            ->assertSee('TypedSpacePC');
    }

    public function test_filter_unit_finds_a_unit_named_with_arabic_alef_madda(): void
    {
        // #705: normalize() maps آ (U+0622) to ا (U+0627), but the stored name
        // keeps the آ, so a plain LIKE can never match. Same failure mode as the
        // ZWNJ case, different character.
        $data = $this->seedUnitWithHardware('واحد آموزش 43', 'AlefPC');

        Livewire::test('hardware.index')
            ->set('filterUnit', $data['unit']->name)
            ->assertSee('AlefPC');
    }

    public function test_filter_unit_matches_when_the_user_types_plain_alef(): void
    {
        // The other direction: a user typing ا must still find واحد آموزش.
        $data = $this->seedUnitWithHardware('واحد آموزش 44', 'TypedAlefPC');

        Livewire::test('hardware.index')
            ->set('filterUnit', 'واحد اموزش 44')
            ->assertSee('TypedAlefPC');
    }

    public function test_filter_unit_matches_persian_digits_stored_in_the_name(): void
    {
        // normalizeForSearch() turns ۴۵ into 45 on the term side, so the column
        // must be folded the same way or a name stored with Persian digits is
        // invisible to its own filter (review note on #711).
        $data = $this->seedUnitWithHardware('واحد بهداشت ۴۵', 'PersianDigitPC');

        Livewire::test('hardware.index')
            ->set('filterUnit', '۴۵')
            ->assertSee('PersianDigitPC');
    }

    public function test_filter_unit_does_not_leak_other_units(): void
    {
        $data = $this->seedUnitWithHardware('واحد بهداشت حرفه‌ای 42', 'MinePC');

        $other = Unit::factory()->create(['name' => 'بیمارستان کاملا متفاوت 99']);
        $otherPerson = Person::factory()->create(['u_id' => $other->id]);
        Hardware::create([
            'n_code' => $otherPerson->n_code,
            'pc_name' => 'TheirsPC',
            'type' => 'PC', 'os' => 'Windows 10',
            'cpu' => 'Intel i5', 'ram' => '8GB', 'hdd' => '256GB SSD',
        ]);

        Livewire::test('hardware.index')
            ->set('filterUnit', $data['unit']->name)
            ->assertSee('MinePC')
            ->assertDontSee('TheirsPC');
    }

    /**
     * Locks in the fix for the second root cause (#705): Postgres sequences are
     * non-transactional, so RefreshDatabase restarts ids at 1 for every test.
     * AccessService keys its cache on exactly those, so
     * `accessible_units:v{version}:{user_id}:{session_unit_id}:{md5(ids)}` is
     * byte-identical across tests and a stale answer written by one test is
     * served to the next.
     *
     * The `cache()->flush()` in setUp is what stops that. Asserted the only way
     * that is meaningful — through the component: this test's rows must produce
     * this test's result, whatever ran before it.
     */
    public function test_results_come_from_this_tests_own_rows(): void
    {
        $data = $this->seedUnitWithHardware('واحد بهداشت حرفه‌ای 50', 'ScopedPC');

        $other = Unit::factory()->create(['name' => 'واحد کاملا دیگر 51']);
        $otherPerson = Person::factory()->create(['u_id' => $other->id]);
        Hardware::create([
            'n_code' => $otherPerson->n_code,
            'pc_name' => 'OutOfScopePC',
            'type' => 'PC', 'os' => 'Windows 10',
            'cpu' => 'Intel i5', 'ram' => '8GB', 'hdd' => '256GB SSD',
        ]);

        $this->assertSame(
            [$data['unit']->id],
            app(AccessService::class)->accessibleUnitIds(),
            'org scope must resolve from this test\'s own session and rows'
        );

        Livewire::test('hardware.index')
            ->assertSee('ScopedPC')
            ->assertDontSee('OutOfScopePC');
    }

    public function test_filter_still_escapes_like_wildcards(): void
    {
        // The escape must survive the fix: a name of 100% must not act as a
        // wildcard and match every unit.
        $data = $this->seedUnitWithHardware('واحد 100% تست', 'PctPC');
        Unit::factory()->create(['name' => 'واحد متفاوت 1']);

        Livewire::test('hardware.index')
            ->set('filterUnit', $data['unit']->name)
            ->assertSee('PctPC');
    }

    /**
     * Regression guard for the filterPerson boolean logic: every branch must
     * be an OR. An AND between l_name and n_code hides the row when filtering
     * by national code alone (a name never matches a digit-only code, and a
     * code never matches a name).
     */
    public function test_filter_person_matches_last_name_and_national_code(): void
    {
        $data = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($data['user']);
        Session::put('current_unit_id', $data['unit']->id);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'سارا', 'l_name' => 'رضایی',
            't_id' => DB::table('tahsils')->first()->id,
            'e_id' => DB::table('estekhdams')->first()->id,
            's_id' => DB::table('semats')->first()->id,
            'r_id' => DB::table('radifs')->first()->id,
            'u_id' => $data['unit']->id,
        ]);
        Hardware::create([
            'n_code' => $nCode,
            'pc_name' => 'PC-Sara',
            'type' => 'PC', 'os' => 'Windows 10',
            'cpu' => 'Intel i5', 'ram' => '8GB', 'hdd' => '256GB SSD',
        ]);

        // Last name alone must find the row.
        Livewire::test('hardware.index')
            ->set('filterPerson', 'رضایی')
            ->assertSee('PC-Sara');

        // National code alone must find the row.
        Livewire::test('hardware.index')
            ->set('filterPerson', $nCode)
            ->assertSee('PC-Sara');
    }
}
