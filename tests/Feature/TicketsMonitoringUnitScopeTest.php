<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #918 — `tickets.monitoring` ran two unscoped unit lookups, so a viewer
 * scoped to one unit could still enumerate (and name) every ticket-receiving
 * unit in the organization:
 *
 *  - `loadData()`'s filter dropdown searched `units` org-wide;
 *  - `$currentUnit` resolved `#[Url] $selectedUnitId` with a bare
 *    `Unit::find()`, reachable by URL alone and rendered in the filter badge.
 *
 * Ticket rows were never affected — `tickets()` is `->accessible()` and
 * `showTicket()` re-checks `in_array($ticket->unit_id, $accessibleIds)` — so
 * this is a directory disclosure, not a data-access bypass. These tests pin
 * the directory boundary.
 *
 * Lives in its own file on purpose: `TicketsMonitoringLivewireTest` is owned
 * by another peer working the same component.
 */
class TicketsMonitoringUnitScopeTest extends TestCase
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
     * A unit the viewer cannot reach, but which the dropdown would happily
     * list if the lookup were not scoped.
     *
     * `can_receive_tickets` MUST be set explicitly: `UnitFactory` sets neither
     * it nor `is_active`, and the migration default for
     * `can_receive_tickets` is `false` — so a bare `Unit::factory()->create()`
     * is filtered out by *eligibility* rather than by *scope*, and the
     * negative assertions below would go green against unfixed code, proving
     * nothing. Setting it true is what makes the assertion meaningful.
     */
    private function foreignUnit(): Unit
    {
        return Unit::create([
            'name' => 'بیمارستان نامحرم نظارت',
            'can_receive_tickets' => true,
            'is_active' => true,
        ]);
    }

    public function test_unit_filter_dropdown_does_not_list_an_out_of_scope_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_all_tickets']);
        $foreign = $this->foreignUnit();
        $this->actingAs($user);

        $component = Livewire::test('tickets.monitoring')
            ->set('unitSearch', 'نامحرم')
            ->call('loadData');

        $ids = array_column($component->get('filterUnits'), 'id');

        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_selected_unit_outside_scope_resolves_to_null_and_renders_no_name(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_all_tickets']);
        $foreign = $this->foreignUnit();
        $this->actingAs($user);

        $component = Livewire::test('tickets.monitoring')
            ->set('selectedUnitId', $foreign->id);

        // The property must be null...
        $this->assertNull($component->get('currentUnit'));

        // ...and so must the markup. A property-only assertion cannot see the
        // «فیلتر: نامشخص» badge the id-only guard used to leave behind, so the
        // rendered HTML is asserted too.
        $this->assertStringNotContainsString($foreign->name, $component->html());
    }

    public function test_the_viewers_own_unit_still_resolves(): void
    {
        // The negative assertions above would also pass against a component
        // that resolves nobody at all, so pin the positive direction: a unit
        // inside the viewer's scope must still resolve and still show a badge.
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['view_all_tickets']);
        $unit->update(['can_receive_tickets' => true]);
        $this->actingAs($user);

        $component = Livewire::test('tickets.monitoring')
            ->set('selectedUnitId', $unit->id);

        $this->assertNotNull($component->get('currentUnit'));
        $this->assertEquals($unit->id, $component->get('currentUnit')->id);
    }

    public function test_an_empty_scope_resolves_no_unit_at_all(): void
    {
        // `accessibleUnitIds()` is legitimately `[]` for an account with no
        // `user_units` row and no `person.u_id`, and `UserFactory` always
        // attaches a person with a `u_id` — so the session unit is dropped and
        // `u_id` nulled explicitly to reach the `[]` branch.
        $foreign = $this->foreignUnit();

        // `UserFactory` always attaches a `Person` with a `u_id`, which
        // `AccessService` falls back to — so `u_id` is nulled explicitly.
        $user = User::factory()->create();
        $user->person->update(['u_id' => null]);
        $user->givePermissionTo('view_all_tickets');

        Session::forget('current_unit_id');
        $this->actingAs($user);

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds());

        $component = Livewire::test('tickets.monitoring')
            ->set('selectedUnitId', $foreign->id)
            ->set('unitSearch', 'نامحرم')
            ->call('loadData');

        // Fail-closed: an empty scope is "in scope of nothing", never
        // "unrestricted" (AGENTS.md). Both lookups must come back empty.
        $this->assertNull($component->get('currentUnit'));
        $this->assertEmpty($component->get('filterUnits'));
    }

    public function test_select_unit_for_filter_refuses_an_out_of_scope_id(): void
    {
        // `selectUnitForFilter` is a public Livewire method, so the guard
        // belongs here — repo convention (`unit.tree`'s `selectNode` docblock,
        // resources/views/livewire/unit/tree.blade.php:29-31) is that a public
        // id-taking method validates the id itself rather than relying on the
        // caller's ordering.
        ['user' => $user] = $this->createUserWithUnit(['view_all_tickets']);
        $foreign = $this->foreignUnit();
        $this->actingAs($user);

        $component = Livewire::test('tickets.monitoring')
            ->call('selectUnitForFilter', $foreign->id);

        $this->assertNull($component->get('currentUnit'));
    }
}
