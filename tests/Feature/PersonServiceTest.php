<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use App\Services\PersonService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1896 — `PersonService::update()` is the single write path for a person
 * update that may transfer units. `persons.u_id` and the linked user's
 * `user_units.is_primary` must move together, and a no-op edit must leave the
 * pivot and the access cache alone.
 */
covers(PersonService::class);

class PersonServiceTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected Unit $unitA;

    protected Unit $unitB;

    protected Person $person;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLookupTables();

        $this->unitA = Unit::create(['name' => 'واحد الف']);
        $this->unitB = Unit::create(['name' => 'واحد ب']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $this->person = Person::create([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'احمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $this->unitA->id,
        ]);
        $this->user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unitA->id, ['role' => 'staff', 'is_primary' => true]);

        // Re-read the person: `create()` leaves `wasRecentlyCreated` true on the
        // in-memory instance, which makes every later `saved` event bump the
        // `unit_hierarchy` cache version (Person::saved) and would mask the
        // no-op contract this suite pins.
        $this->person = $this->person->fresh();
    }

    public function test_update_without_unit_change_leaves_pivot_and_cache_untouched(): void
    {
        $cache = app(CacheInvalidationServiceInterface::class);
        $versionBefore = $cache->getVersion('unit_hierarchy');

        app(PersonService::class)->update($this->person, ['f_name' => 'رضا']);
        app(PersonService::class)->update($this->person, ['f_name' => 'رضا', 'u_id' => $this->unitA->id]);

        $this->assertSame('رضا', $this->person->fresh()->f_name);
        $this->assertSame($this->unitA->id, $this->user->fresh()->primaryUnit()?->id);
        $this->assertSame(1, DB::table('user_units')->where('user_id', $this->user->id)->count());
        $this->assertSame($versionBefore, $cache->getVersion('unit_hierarchy'));
    }

    public function test_update_transfer_moves_primary_unit(): void
    {
        app(PersonService::class)->update($this->person, ['u_id' => $this->unitB->id]);

        $this->assertSame($this->unitB->id, $this->person->fresh()->u_id);
        $this->assertSame($this->unitB->id, $this->user->fresh()->primaryUnit()?->id);
        $this->assertSame(
            [$this->unitB->id],
            DB::table('user_units')
                ->where('user_id', $this->user->id)
                ->where('is_primary', true)
                ->pluck('unit_id')
                ->all()
        );
    }

    /**
     * #1896: the person update and the pivot sync share one transaction, so a
     * failing pivot write must roll the `u_id` change back too.
     *
     * No real constraint can be violated by `setPrimaryUnit()` (the partial
     * unique index is preserved by its clear-then-set order and every FK is
     * pre-validated), so force the failure with a temporary CHECK constraint.
     * `RefreshDatabase` wraps the test in a transaction, which makes the
     * service's `DB::transaction` a savepoint: the failure rolls back to it and
     * the outer transaction survives, so the constraint can be dropped again.
     */
    public function test_failed_pivot_write_rolls_back_the_person_update(): void
    {
        DB::statement(
            'ALTER TABLE user_units ADD CONSTRAINT person_transfer_block_primary '.
            "CHECK (NOT is_primary OR unit_id <> {$this->unitB->id})"
        );

        try {
            app(PersonService::class)->update($this->person, ['u_id' => $this->unitB->id]);
            $this->fail('pivot write was expected to fail');
        } catch (QueryException) {
            // expected
        } finally {
            DB::statement('ALTER TABLE user_units DROP CONSTRAINT IF EXISTS person_transfer_block_primary');
        }

        $this->assertSame($this->unitA->id, $this->person->fresh()->u_id);
        $this->assertSame($this->unitA->id, $this->user->fresh()->primaryUnit()?->id);
        $this->assertDatabaseMissing('user_units', [
            'user_id' => $this->user->id,
            'unit_id' => $this->unitB->id,
        ]);
    }
}
