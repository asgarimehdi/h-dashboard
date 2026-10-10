<?php

namespace Tests\Feature;

use App\Models\Hardware;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitDeletionImpact;
use App\Services\UnitDeletionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #949 steps 2–3 — the impact report that runs before any delete.
 *
 * `units` has eight inbound foreign keys and the two `RESTRICT` ones were the
 * only ones the code guarded. The other six do what the database tells them to,
 * quietly: `persons.u_id`, `todos.unit_id` → NULL, `user_units` → CASCADE.
 * This service is the single place that knows what a delete would destroy, so
 * the Livewire list and the API controller cannot drift apart on it.
 *
 * `maintenance_schedules` is deliberately absent: that half of the same cascade
 * is issue #876, and it is the one table whose guard is not part of #949's
 * approved scope.
 */
class UnitDeletionServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    private function impact(Unit $unit): UnitDeletionImpact
    {
        return app(UnitDeletionService::class)->impact($unit);
    }

    /**
     * `user_units` is a bare pivot — no model, no factory — so the row has to
     * be written by hand.
     *
     * The user is bound to a caller-supplied `Person`, never created bare:
     * `UserFactory::configure()` auto-creates a backing `Person` pinned to
     * `Unit::query()->value('id')`, i.e. the first unit in the table. Creating
     * a user with no person of its own would silently add that unit's
     * `persons` count and quietly invalidate whatever the test was measuring.
     */
    private function attachUserToUnit(Person $person, Unit $unit): User
    {
        $user = User::factory()->create(['n_code' => $person->n_code]);

        DB::table('user_units')->insert([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'role' => 'staff',
            'is_primary' => true,
        ]);

        return $user;
    }

    public function test_an_empty_unit_blocks_nothing(): void
    {
        $unit = Unit::factory()->create();

        $impact = $this->impact($unit);

        $this->assertSame([], $impact->blockers());
        $this->assertTrue($impact->isClear());
    }

    /**
     * The pinned contract from the «تأیید گیت» comment: with step 3's guard,
     * "delete a unit that has personnel" = refuse.
     */
    public function test_personnel_block_the_delete_and_are_counted(): void
    {
        $unit = Unit::factory()->create();
        Person::factory()->count(3)->create(['u_id' => $unit->id]);

        $impact = $this->impact($unit);

        $this->assertSame(3, $impact->persons);
        $this->assertFalse($impact->isClear());
        $this->assertSame(['persons' => 3], $impact->blockers());
    }

    public function test_hardware_is_counted_through_its_owner(): void
    {
        $unit = Unit::factory()->create();
        $person = Person::factory()->create(['u_id' => $unit->id]);
        Hardware::factory()->count(2)->create(['n_code' => $person->n_code]);

        // Another unit's assets must not be attributed to this one.
        $otherUnit = Unit::factory()->create();
        $otherPerson = Person::factory()->create(['u_id' => $otherUnit->id]);
        Hardware::factory()->create(['n_code' => $otherPerson->n_code]);

        $impact = $this->impact($unit);

        $this->assertSame(2, $impact->hardware);
        $this->assertSame(1, $impact->persons);
    }

    public function test_todos_block_the_delete_and_are_counted(): void
    {
        $unit = Unit::factory()->create();
        Todo::factory()->count(2)->create(['unit_id' => $unit->id]);

        $impact = $this->impact($unit);

        $this->assertSame(2, $impact->todos);
        $this->assertArrayHasKey('todos', $impact->blockers());
    }

    /**
     * `uu_unit_fk` is the only cascade of the six, and #949 measured it
     * destroying 155 of 318 accounts. `user_units` carries a unique index on
     * (user_id, unit_id), so one row is one account and no DISTINCT is needed
     * to keep the count honest.
     */
    public function test_accounts_are_counted(): void
    {
        $unit = Unit::factory()->create();
        $this->attachUserToUnit(Person::factory()->create(['u_id' => null]), $unit);
        $this->attachUserToUnit(Person::factory()->create(['u_id' => null]), $unit);

        $impact = $this->impact($unit);

        $this->assertSame(2, $impact->accounts);
        $this->assertSame(0, $impact->persons);
        $this->assertArrayHasKey('accounts', $impact->blockers());
    }

    public function test_tickets_and_children_are_reported_even_though_the_database_restricts_them(): void
    {
        $unit = Unit::factory()->create();
        Ticket::create([
            'ticket_code' => 'T-1',
            'subject' => 's',
            'content' => 'c',
            'status' => 'open',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ]);
        Unit::factory()->create(['parent_id' => $unit->id]);

        $impact = $this->impact($unit);

        $this->assertSame(1, $impact->tickets);
        $this->assertSame(1, $impact->children);
    }

    /**
     * The whole point of the report: one delete must not silently reach six
     * tables. Every non-zero table has to appear, with its own count.
     */
    public function test_every_reachable_table_appears_in_the_blockers(): void
    {
        $unit = Unit::factory()->create();
        Unit::factory()->create(['parent_id' => $unit->id]);
        $person = Person::factory()->create(['u_id' => $unit->id]);
        Hardware::factory()->create(['n_code' => $person->n_code]);
        Todo::factory()->create(['unit_id' => $unit->id]);
        Ticket::create([
            'ticket_code' => 'T-2',
            'subject' => 's',
            'content' => 'c',
            'status' => 'open',
            'priority' => 'normal',
            'unit_id' => $unit->id,
        ]);
        $this->attachUserToUnit($person, $unit);

        $impact = $this->impact($unit);

        $this->assertSame([
            'children' => 1,
            'tickets' => 1,
            'persons' => 1,
            'hardware' => 1,
            'todos' => 1,
            'accounts' => 1,
        ], $impact->blockers());
    }
}
