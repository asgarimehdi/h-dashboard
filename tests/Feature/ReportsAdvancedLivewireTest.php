<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

class ReportsAdvancedLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_advanced_report_renders_for_authorized_user(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertStatus(200);
    }

    public function test_advanced_report_mounts_with_default_dates(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('reportType', 'tickets')
            ->assertSet('statusFilter', 'all')
            ->assertSet('dateFrom', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val))
            ->assertSet('dateTo', fn ($val) => ! empty($val) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $val));
    }

    public function test_advanced_report_has_units_loaded(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => count($units) >= 1);
    }
}
