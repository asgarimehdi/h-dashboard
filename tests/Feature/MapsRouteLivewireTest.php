<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class MapsRouteLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Auth & permissions ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/route')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        $result = $this->createUserWithUnit(['manage_users']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        DB::table('user_units')->where('user_id', $result['user']->id)->update(['is_primary' => true]);
        session()->put('current_unit_id', $result['unit']->id);

        $this->get('/maps/route')->assertStatus(403);
    }

    public function test_renders(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertStatus(200)
            ->assertSee('محاسبه فاصله جاده‌ای بدون API');
    }

    // ==================== Mount / properties ====================

    public function test_mount_sets_default_waypoints(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSet('waypoint1', '36.149617, 49.217189')
            ->assertSet('waypoint2', '36.146862, 49.229586');
    }

    public function test_mount_sets_routing_url(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        $expectedUrl = config('map.routing_url', 'http://127.0.0.1:5000');

        Livewire::test('maps/route')
            ->assertSet('routing_url', $expectedUrl);
    }

    public function test_mount_sets_tile_template(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        $expectedTemplate = config('map.tile_url_template', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');

        Livewire::test('maps/route')
            ->assertSet('map_tile_template', $expectedTemplate);
    }

    // ==================== Content ====================

    public function test_renders_distance_elements(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSee('فاصله جاده‌ای')
            ->assertSee('زمان تقریبی سفر');
    }

    public function test_renders_toggle_label(): void
    {
        $result = $this->createUserWithUnit(['map']);
        $user = $result['user'];
        $unit = $result['unit'];
        $this->actingAs($result['user']);
        session()->put('current_unit_id', $result['unit']->id);

        Livewire::test('maps/route')
            ->assertSee('نمایش متنی مسیر')
            ->assertSee('می‌توانید نقاط را جابجا کنید');
    }
}
