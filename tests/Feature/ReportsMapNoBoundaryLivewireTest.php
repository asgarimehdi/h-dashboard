<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsMapNoBoundaryLivewireTest extends TestCase
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

    protected function createUserWithUnit(string $permission = ''): array
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

        return ['user' => $user, 'unit' => $unit];
    }

    // ==================== Auth / page load ====================

    public function test_guest_302(): void
    {
        $this->get('/reports/map-no-boundary')->assertRedirect('/login');
    }

    public function test_no_context_redirect(): void
    {
        // User with no units attached but has a person with u_id
        // → middleware auto-sets current_unit_id from person.u_id
        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::create(['name' => 'واحد اصلی']);
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->actingAs($user);

        Livewire::test('reports.map-no-boundary')
            ->assertStatus(200);
    }

    public function test_renders_stats_and_map(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.map-no-boundary')
            ->assertStatus(200)
            ->assertSee('نقاط فاقد مرز در نقشه')
            ->assertSee('کل واحدهای فاقد مرز')
            ->assertSee('دارای مختصات')
            ->assertSee('بدون مختصات');
    }

    // ==================== Help modal ====================

    public function test_help_modal(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.map-no-boundary')
            ->assertSet('showHelpModal', false);
    }

    // ==================== Stats counts ====================

    public function test_counts_match(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create child units under user's unit (so they appear in accessible scope)
        // 2 with coords (boundary=null), 1 without coords (boundary=null)
        Unit::create(['name' => 'واحد با مختصات ۱', 'parent_id' => $unit->id, 'lat' => 35.6892, 'lng' => 51.3890]);
        Unit::create(['name' => 'واحد با مختصات ۲', 'parent_id' => $unit->id, 'lat' => 32.4279, 'lng' => 53.6880]);
        Unit::create(['name' => 'واحد بدون مختصات', 'parent_id' => $unit->id]);

        Livewire::test('reports.map-no-boundary')
            ->assertSee('3')   // total (including user's own unit which also has no boundary)
            ->assertSee('2')   // with coords
            ->assertSee('2');  // without coords (user's unit + the one without coords)
    }

    // ==================== Table visibility ====================

    public function test_table_visibility(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create child units: 1 with coords (should NOT appear in table), 1 without (should appear)
        Unit::create(['name' => 'واحد مختصات‌دار', 'parent_id' => $unit->id, 'lat' => 35.6892, 'lng' => 51.3890]);
        Unit::create(['name' => 'واحد بی‌مختصات', 'parent_id' => $unit->id]);

        Livewire::test('reports.map-no-boundary')
            ->assertSee('واحد بی‌مختصات');
    }

    // ==================== Embedded map ====================

    public function test_child_map_present(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.map-no-boundary')
            ->assertStatus(200)
            ->assertSeeHtml('maps.map');
    }

    // ==================== Scope filtering ====================

    public function test_scope_filtering(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        // In-scope: child of user's unit
        Unit::create(['name' => 'در محدوده', 'parent_id' => $unit->id]);

        // Out-of-scope: unrelated unit
        Unit::create(['name' => 'خارج از محدوده']);

        Livewire::test('reports.map-no-boundary')
            ->assertSee('در محدوده')
            ->assertDontSee('خارج از محدوده');
    }

    // ==================== Empty scope ====================

    public function test_empty_scope(): void
    {
        // User with no units and person with u_id = null
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->actingAs($user);

        Livewire::test('reports.map-no-boundary')
            ->assertStatus(200)
            ->assertSee('0');
    }
}
