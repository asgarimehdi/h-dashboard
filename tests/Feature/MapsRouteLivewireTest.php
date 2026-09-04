<?php

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class MapsRouteLivewireTest extends TestCase
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

        DB::statement("SELECT setval('tahsils_id_seq', GREATEST((SELECT MAX(id) FROM tahsils), 1))");
        DB::statement("SELECT setval('estekhdams_id_seq', GREATEST((SELECT MAX(id) FROM estekhdams), 1))");
        DB::statement("SELECT setval('semats_id_seq', GREATEST((SELECT MAX(id) FROM semats), 1))");
        DB::statement("SELECT setval('radifs_id_seq', GREATEST((SELECT MAX(id) FROM radifs), 1))");
    }

    protected function createUserWithUnit(string $permission = 'map'): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo($permission);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return ['user' => $user, 'unit' => $unit];
    }

    // ==================== Auth & permissions ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/route')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        $result = $this->createUserWithUnit('manage_users');
        $this->actingAs($result['user']);
        DB::table('user_units')->where('user_id', $result['user']->id)->update(['is_primary' => true]);
        session()->put('current_unit_id', $result['unit']->id);

        $this->get('/maps/route')->assertStatus(403);
    }

    public function test_renders(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertStatus(200)
            ->assertSee('محاسبه فاصله جاده‌ای بدون API');
    }

    // ==================== Mount / properties ====================

    public function test_mount_sets_default_waypoints(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSet('waypoint1', '36.149617, 49.217189')
            ->assertSet('waypoint2', '36.146862, 49.229586');
    }

    public function test_mount_sets_routing_url(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        $expectedUrl = config('map.routing_url', 'http://127.0.0.1:5000');

        Livewire::test('maps/route')
            ->assertSet('routing_url', $expectedUrl);
    }

    public function test_mount_sets_tile_template(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        $expectedTemplate = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');

        Livewire::test('maps/route')
            ->assertSet('map_tile_template', $expectedTemplate);
    }

    // ==================== Content ====================

    public function test_renders_distance_elements(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSee('فاصله جاده‌ای')
            ->assertSee('زمان تقریبی سفر');
    }

    public function test_renders_toggle_label(): void
    {
        $result = $this->createUserWithUnit();
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSee('نمایش متنی مسیر')
            ->assertSee('می‌توانید نقاط را جابجا کنید');
    }
}
