<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #857 regression — `units.index` must fail closed on an empty unit scope.
 *
 * Uses the `ReportsEmptyScopeTest` shape: an account that HOLDS the page's
 * permission (`organization`) but has NO unit at all — no `user_units` row and
 * no `person.u_id`. `AccessibleUnitIds()` is legitimately `[]` for such an
 * account, and `[]` means "in scope of nothing", never "unrestricted".
 *
 * The guard this pins is the one that used to read
 * `if (! empty($accessibleIds)) { $query->whereIn('id', $accessibleIds); }`,
 * where the empty array dropped the predicate entirely and the page listed the
 * whole organization.
 */
class UnitsEmptyScopeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * A user holding `organization` with no unit reachable at all.
     */
    private function actingAsUserWithoutUnit(): User
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'بدون واحد',
            'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1,
            'u_id' => null,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('organization');

        $this->actingAs($user);

        return $user;
    }

    public function test_an_empty_scope_really_is_empty(): void
    {
        $this->actingAsUserWithoutUnit();

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds());
    }

    public function test_units_page_lists_nothing_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        Unit::create(['name' => 'واحد بیرونی تست']);
        Unit::create(['name' => 'بیمارستان خارج از دسترس']);

        $this->get('/units')->assertStatus(200)->assertDontSee('واحد بیرونی تست');

        Livewire::test('units.index')->assertDontSee('واحد بیرونی تست');
    }

    public function test_the_paginator_reports_zero_rows_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        Unit::create(['name' => 'واحد بیرونی تست']);
        Unit::create(['name' => 'بیمارستان خارج از دسترس']);

        $component = Livewire::test('units.index');

        $this->assertSame(0, $component->instance()->units()->total());
    }

    public function test_a_search_on_an_empty_scope_still_returns_nothing(): void
    {
        $this->actingAsUserWithoutUnit();

        Unit::create(['name' => 'بیمارستان نمونه']);

        Livewire::test('units.index')
            ->set('search', 'بیمارستان')
            ->assertDontSee('بیمارستان نمونه');

        $this->assertSame(0, Livewire::test('units.index')->instance()->units()->total());
    }
}
