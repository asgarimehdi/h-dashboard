<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

class MapsRoute2LivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    // ==================== Guest / Auth gates ====================

    public function test_guest_302(): void
    {
        $this->get('/maps/route2')->assertRedirect('/login');
    }

    public function test_unauthorized_403(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);
        $this->get('/maps/route2')->assertStatus(403);
    }

    public function test_renders(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->assertStatus(200)
            ->assertSee('محاسبه فاصله جاده‌ای');
    }

    // ==================== Interaction tests ====================

    public function test_create_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->assertSet('start_point', '35.6892,51.3890')
            ->assertSet('end_point', '32.6546,51.6675');
    }

    public function test_edit_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->set('start_point', '36.3200,53.3200')
            ->assertSet('start_point', '36.3200,53.3200')
            ->assertSet('end_point', '32.6546,51.6675');
    }

    public function test_delete_route(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->set('start_point', '')
            ->set('end_point', '')
            ->assertSet('start_point', '')
            ->assertSet('end_point', '');
    }

    // ==================== Swap points ====================

    public function test_swap_points(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['map']);
        $this->actingAs($user);
        session()->put('current_unit_id', $unit->id);

        Livewire::test('maps/route2')
            ->set('start_point', '35.6892,51.3890')
            ->set('end_point', '32.6546,51.6675')
            ->call('swapPoints')
            ->assertSet('start_point', '32.6546,51.6675')
            ->assertSet('end_point', '35.6892,51.3890');
    }
}
