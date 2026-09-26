<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\ZabbixDeviceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

class ItComponentsLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(ZabbixDeviceSeeder::class);
        $this->seedLookupTables();
    }

    public function test_it_networks_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('it.networks')
            ->assertStatus(200);
    }

    public function test_it_networks_has_network_items(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('it.networks')
            ->assertSet('networkItems', fn ($items) => count($items) > 0);
    }

    public function test_it_wireless_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('it.wireless')
            ->assertStatus(200);
    }

    public function test_it_wireless_has_signal_items(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('it.wireless')
            ->assertSet('signalItems', fn ($items) => count($items) > 0);
    }
}
