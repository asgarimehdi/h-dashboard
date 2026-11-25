<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserUnitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1803 — `is_primary` on the `user_units` pivot means "the user's one
 * primary unit", but nothing enforced exactly-one. `User::setPrimaryUnit()`
 * is the only sanctioned writer, and a partial unique index
 * (`user_units_one_primary_per_user`) is the database guarantee behind it.
 */
covers(User::class);

class UserPrimaryUnitInvariantTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const INDEX = 'user_units_one_primary_per_user';

    private const MIGRATION = '2026_11_25_000002_add_primary_unique_index_to_user_units.php';

    protected Unit $unitA;

    protected Unit $unitB;

    protected Unit $unitC;

    protected User $user;

    protected string $nCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        $this->unitA = Unit::create(['name' => 'واحد الف']);
        $this->unitB = Unit::create(['name' => 'واحد ب']);
        $this->unitC = Unit::create(['name' => 'واحد ج']);

        $this->nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $this->nCode, 'f_name' => 'علی', 'l_name' => 'احمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $this->unitA->id,
        ]);
        $this->user = User::create(['n_code' => $this->nCode, 'password' => Hash::make('password')]);
        $this->user->units()->attach($this->unitA->id, ['role' => 'staff', 'is_primary' => true]);
    }

    private function primaryUnitIds(): array
    {
        return DB::table('user_units')
            ->where('user_id', $this->user->id)
            ->where('is_primary', true)
            ->pluck('unit_id')
            ->all();
    }

    public function test_set_primary_unit_clears_the_old_primary(): void
    {
        $this->user->setPrimaryUnit($this->unitB->id);

        $this->assertSame([$this->unitB->id], $this->primaryUnitIds());
        $this->assertDatabaseHas('user_units', [
            'user_id' => $this->user->id, 'unit_id' => $this->unitA->id, 'is_primary' => false,
        ]);
        $this->assertSame($this->unitB->id, $this->user->fresh()->primaryUnit()?->id);
    }

    public function test_set_primary_unit_attaches_a_missing_unit_as_staff(): void
    {
        $this->user->setPrimaryUnit($this->unitC->id);

        $this->assertSame([$this->unitC->id], $this->primaryUnitIds());
        $this->assertDatabaseHas('user_units', [
            'user_id' => $this->user->id, 'unit_id' => $this->unitC->id, 'role' => 'staff',
        ]);
    }

    public function test_set_primary_unit_preserves_an_existing_role(): void
    {
        $this->user->units()->attach($this->unitB->id, ['role' => 'responsible', 'is_primary' => false]);

        $this->user->setPrimaryUnit($this->unitB->id);

        $this->assertDatabaseHas('user_units', [
            'user_id' => $this->user->id, 'unit_id' => $this->unitB->id,
            'role' => 'responsible', 'is_primary' => true,
        ]);
        $this->assertSame([$this->unitB->id], $this->primaryUnitIds());
    }

    public function test_set_primary_unit_is_idempotent(): void
    {
        $this->user->setPrimaryUnit($this->unitB->id);
        $this->user->setPrimaryUnit($this->unitB->id);

        $this->assertSame([$this->unitB->id], $this->primaryUnitIds());
        $this->assertSame(2, DB::table('user_units')->where('user_id', $this->user->id)->count());
    }

    public function test_partial_unique_index_rejects_a_second_primary_row(): void
    {
        // A nested transaction gets a SAVEPOINT, so the failed INSERT rolls
        // back to it instead of poisoning RefreshDatabase's outer transaction.
        expect(fn () => DB::transaction(fn () => DB::table('user_units')->insert([
            'user_id' => $this->user->id,
            'unit_id' => $this->unitB->id,
            'role' => 'staff',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class);

        $this->assertSame([$this->unitA->id], $this->primaryUnitIds());
    }

    public function test_index_allows_two_users_to_each_have_a_primary(): void
    {
        $otherNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $otherNCode, 'f_name' => 'مریم', 'l_name' => 'رضایی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $this->unitA->id,
        ]);
        $otherUser = User::create(['n_code' => $otherNCode, 'password' => Hash::make('password')]);
        $otherUser->units()->attach($this->unitB->id, ['role' => 'staff', 'is_primary' => true]);

        $this->assertSame([$this->unitA->id], $this->primaryUnitIds());
        $this->assertSame([$this->unitB->id], DB::table('user_units')
            ->where('user_id', $otherUser->id)->where('is_primary', true)->pluck('unit_id')->all());
    }

    public function test_migration_repairs_duplicate_primaries_and_rebuilds_the_index(): void
    {
        // The index is already in place from the test DB's migrations; drop it
        // inside the test transaction (PG DDL is transactional, so the
        // rollback restores it) to reproduce the pre-fix duplicate state.
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        DB::table('user_units')->where('user_id', $this->user->id)->update(['is_primary' => false]);
        DB::table('user_units')->where('user_id', $this->user->id)
            ->where('unit_id', $this->unitA->id)->update(['is_primary' => true]); // lower pivot id
        DB::table('user_units')->insert($this->pivotRow($this->user->id, $this->unitB->id, true));

        // A user whose person sits in unit C, which is not one of the two
        // primaries: there is no u_id match, so the highest pivot id wins.
        $looseNCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $looseNCode, 'f_name' => 'سارا', 'l_name' => 'کریمی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $this->unitC->id,
        ]);
        $looseUser = User::create(['n_code' => $looseNCode, 'password' => Hash::make('password')]);
        DB::table('user_units')->insert($this->pivotRow($looseUser->id, $this->unitA->id, true));
        DB::table('user_units')->insert($this->pivotRow($looseUser->id, $this->unitB->id, true));

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->up();

        // The person's current u_id (unit A) beats the higher pivot id (B).
        $this->assertSame([$this->unitA->id], $this->primaryUnitIds());
        $this->assertSame([$this->unitB->id], DB::table('user_units')
            ->where('user_id', $looseUser->id)->where('is_primary', true)->pluck('unit_id')->all());

        // Idempotent: a second run changes nothing.
        $migration->up();
        $this->assertSame([$this->unitA->id], $this->primaryUnitIds());

        // The rebuilt index is live again.
        expect(fn () => DB::transaction(fn () => DB::table('user_units')->insert(
            $this->pivotRow($this->user->id, $this->unitC->id, true)
        )))->toThrow(QueryException::class);
    }

    public function test_user_unit_seeder_does_not_create_a_second_primary(): void
    {
        // The person still sits in unit A, but the user's primary moved to B.
        // Re-running `db:seed` used to write `is_primary = true` straight into
        // the A row, violating the new index. It must go through the helper.
        $this->user->setPrimaryUnit($this->unitB->id);

        $this->seed(UserUnitSeeder::class);

        $this->assertSame([$this->unitA->id], $this->primaryUnitIds());
    }

    private function pivotRow(int $userId, int $unitId, bool $isPrimary): array
    {
        return [
            'user_id' => $userId,
            'unit_id' => $unitId,
            'role' => 'staff',
            'is_primary' => $isPrimary,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
