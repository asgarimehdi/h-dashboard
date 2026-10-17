<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1014 / Plan 007 — the users directory paid ~4 lazy queries per row:
 * `person` + `unit` through the `unit_name` accessor, plus `permissions` +
 * `roles.permissions` inside the MaryUI expansion slot, which renders for
 * EVERY row and is only hidden with CSS. At perPage=20 that was ~80 queries
 * per render, repeated on every search keystroke and pagination click.
 *
 * Fix shape: nested eager loads on `users()` — `roles.permissions`,
 * `permissions` (direct, so Spatie's getAllPermissions() reads an already
 * loaded relation), and `person.unit` (FULL chain — a `person:id,n_code`
 * column subset would make the accessors lazy-load again).
 *
 * Measurement style (same pattern as `ReportsPersonsLivewireTest` #858):
 * `DB::listen` counts are compared against a FIXED BUDGET in two separate
 * test methods — one page with a single in-scope row, one with 20 — instead
 * of exact totals. Fixed-overhead queries (mount lookups, pagination count,
 * accessible-units) legitimately vary by ±1 between renders, so budgets are
 * generous; every per-row lazy load (the N+1 this issue is about) blows the
 * budget of the 20-row method the moment the row count scales. The 20-row
 * render is also a cold session's FIRST render, which pins the expert note
 * that `name` must not pay per-row `persons` queries before warming up.
 */
class UsersDirectoryLivewireTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** Per-table select patterns — quoted names are exact, so `units` never matches `user_units`. @var array<string, string> */
    private const TABLE_PATTERNS = [
        'persons' => '/from "persons"/',
        'units' => '/from "units"/',
        'permissions' => '/from "(permissions|model_has_permissions|role_has_permissions)"/',
    ];

    /**
     * Query budget for one 20-row render, measured post-fix: 19 total
     * (mount lookups + pagination count + 3 eager loads + scope), ~6 per
     * table. The pre-fix run measured 190 total / 63·45·82 per table on the
     * SAME page — that is what any regression would reproduce.
     */
    private const PER_TABLE_BUDGET = 8;

    private const TOTAL_BUDGET = 40;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * An actor with manage_users whose scope is one unit, plus in-scope
     * directory rows (person in that unit + unit pivot + a permission-bearing
     * role so the expansion slot's getAllPermissions() has real work).
     *
     * @param  int  $inScopeUsers  directory rows after the actor itself
     * @return array{actor: User, unit: Unit, role: Role}
     */
    private function directoryFixtures(int $inScopeUsers): array
    {
        $unit = Unit::create(['name' => 'واحد اصلی']);
        Session::put('current_unit_id', $unit->id);

        /** @var Role $role */
        $role = Role::create(['name' => 'operator', 'label' => 'اپراتور']);
        $role->givePermissionTo(Permission::query()->where('name', 'manage_users')->firstOrFail());

        $actor = $this->makeDirectoryUser($unit, 1);
        $actor->givePermissionTo('manage_users');
        $actor->assignRole($role);

        $this->addInScopeUsers($unit, $role, $inScopeUsers, 2);

        return ['actor' => $actor, 'unit' => $unit, 'role' => $role];
    }

    /**
     * Continue the same fixtures with more rows IN THE SAME unit, so a fresh
     * render of the same actor lands a larger page — the ONLY difference
     * between the two budgeted methods is the row count.
     */
    private function addInScopeUsers(Unit $unit, Role $role, int $count, int $startAt): void
    {
        $role->givePermissionTo(Permission::query()->where('name', 'manage_users')->firstOrFail());

        for ($i = $startAt; $i < $startAt + $count; $i++) {
            $user = $this->makeDirectoryUser($unit, $i);
            $user->assignRole($role);
        }
    }

    /** One directory row: person in the unit + user + unit pivot. */
    private function makeDirectoryUser(Unit $unit, int $seq): User
    {
        $nCode = '92'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT);

        Person::create([
            'n_code' => $nCode,
            'f_name' => 'کاربر',
            'l_name' => 'شماره '.$seq,
            'u_id' => $unit->id,
            's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    /**
     * Render the directory (perPage=20) with a fresh DB::listen counter and
     * assert the per-table budgets.
     *
     * NOTE: each test method may use this ONCE — a second listener collection
     * in the same test would accumulate (see the #858 note in
     * ReportsPersonsLivewireTest).
     */
    private function assertRenderWithinBudget(User $actor, string $context): void
    {
        $counts = [];
        $total = 0;

        DB::listen(function ($query) use (&$counts, &$total) {
            $sql = $query->sql;

            if (str_starts_with($sql, 'BEGIN') || str_starts_with($sql, 'COMMIT') || str_starts_with($sql, 'ROLLBACK')) {
                return;
            }

            $total++;

            foreach (self::TABLE_PATTERNS as $table => $pattern) {
                if (preg_match($pattern, $sql) === 1) {
                    $counts[$table] = ($counts[$table] ?? 0) + 1;
                }
            }
        });

        Livewire::actingAs($actor)
            ->test('users.index')
            ->set('perPage', 20)
            ->assertOk();

        expect($total)->toBeLessThanOrEqual(self::TOTAL_BUDGET,
            "[{$context}] {$total} queries for one render of the users directory");

        foreach (self::TABLE_PATTERNS as $table => $pattern) {
            expect($counts[$table] ?? 0)->toBeLessThanOrEqual(self::PER_TABLE_BUDGET,
                "[{$context}] {$counts[$table]} '{$table}' queries — per-row lazy load (N+1) is back");
        }
    }

    public function test_directory_renders_names_and_unit_names_populated(): void
    {
        $f = $this->directoryFixtures(1);

        $component = Livewire::actingAs($f['actor'])
            ->test('users.index')
            ->assertOk();

        $component->assertViewHas('users', function ($users) use ($f) {
            $rows = collect($users->items());

            // The over-eager-projection guard: dropping `withAggregate('person',
            // …)` must not also drop the values the table actually renders —
            // `name` and `unit_name` still resolve through the loaded relations.
            return $rows->count() === 1
                && $rows->first()->name !== null
                && $rows->first()->unit_name === $f['unit']->name;
        });

        $component->assertSee($f['unit']->name);
    }

    /** Single-row page: the fixed-cost baseline. */
    public function test_directory_single_row_page_query_budget(): void
    {
        $f = $this->directoryFixtures(1);

        $this->assertRenderWithinBudget($f['actor'], 'single-row page');
    }

    /**
     * 20-row page (a cold session's first render): must hit the SAME budget.
     * Pre-fix this was 190 queries / 63·45·82 per table.
     */
    public function test_directory_twenty_row_page_same_query_budget(): void
    {
        $f = $this->directoryFixtures(20);

        $this->assertRenderWithinBudget($f['actor'], '20-row page');
    }

    public function test_scope_still_limits_the_directory_to_accessible_units(): void
    {
        $f = $this->directoryFixtures(1);
        $foreignUnit = Unit::create(['name' => 'واحد بیگانه']);

        Person::create([
            'n_code' => '9333333333',
            'f_name' => 'بیرونی',
            'l_name' => 'واحد',
            'u_id' => $foreignUnit->id,
            's_id' => 1, 't_id' => 1, 'e_id' => 1, 'r_id' => 1,
        ]);
        User::create(['n_code' => '9333333333', 'password' => Hash::make('password')]);

        Livewire::actingAs($f['actor'])
            ->test('users.index')
            ->assertOk()
            ->assertViewHas('users', function ($users) {
                $nCodes = collect($users->items())->pluck('n_code');

                return $nCodes->contains('9200000002') && ! $nCodes->contains('9333333333');
            });
    }

    public function test_empty_scope_renders_nobody_but_not_unfiltered(): void
    {
        $f = $this->directoryFixtures(2);

        // `[]` means "in scope of nothing", never "unrestricted" (AGENTS.md).
        $this->mock(AccessService::class, function ($mock) {
            $mock->shouldReceive('accessibleUnitIds')->andReturn([]);
        });

        Livewire::actingAs($f['actor'])
            ->test('users.index')
            ->assertOk()
            ->assertViewHas('users', fn ($users) => $users->count() === 0);
    }
}
