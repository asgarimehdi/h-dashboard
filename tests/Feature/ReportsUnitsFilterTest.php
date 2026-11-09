<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1251 — the units report's `showOnlyNoBoundary` is a `?int`. Livewire
 * coerces an empty-string payload to `null` in
 * `HandleComponents::setComponentPropertyAwareOfTypes`, so the `!== ''` arm of
 * the filter was dead code. This pins that framework behaviour: if a future
 * Livewire upgrade lets `''` reach the typed property, the simplified filter
 * (and this test) must be revisited instead of silently changing the report.
 */
class ReportsUnitsFilterTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_empty_boundary_filter_is_coerced_to_null(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);
        session(['current_unit_id' => $unit->id]);

        Livewire::test('reports.units')
            ->set('showOnlyNoBoundary', '')
            ->assertSet('showOnlyNoBoundary', null);
    }
}
