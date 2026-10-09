<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #819 — the `/reports/*` components guarded their unit scope with
 * `->when($accessibleIds, fn ($q) => $q->whereIn(...))`. `Conditionable::when()`
 * runs the callback only for a **truthy** value, so an EMPTY scope skipped the
 * predicate entirely and the page rendered every unit's rows.
 *
 * An empty scope is reachable: `AccessService::accessibleUnitIds()` returns `[]`
 * for an authenticated account with no `user_units` row and no `person.u_id`.
 * These tests pin the fail-closed contract for each page, matched to what that
 * page actually leaks.
 */
#[CoversNothing]
class ReportsEmptyScopeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const FOREIGN_N_CODE = '1111111112';

    private const FOREIGN_TODO_TITLE = 'کاربرد خارج از محدوده';

    private const FOREIGN_UNIT_NAME = 'واحد بیرونی تست';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * An authenticated, `manage_personnel`-holding account with no unit at all,
     * plus one foreign unit's rows that it must never see.
     */
    private function actingAsUserWithoutUnit(): User
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون واحد', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('manage_personnel');

        $foreignUnit = Unit::create(['name' => self::FOREIGN_UNIT_NAME]);

        Person::create([
            'n_code' => self::FOREIGN_N_CODE, 'f_name' => 'خارجی', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $foreignUnit->id,
        ]);

        Todo::create([
            'title' => self::FOREIGN_TODO_TITLE,
            'unit_id' => $foreignUnit->id,
            'is_completed' => false,
            'start_at' => now()->startOfDay(),
            'end_at' => now()->startOfDay(),
        ]);

        $this->actingAs($user);

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds(), 'the fixture must really be an empty scope');

        return $user;
    }

    public function test_persons_report_leaks_nothing_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        $component = Livewire::test('reports.persons')->assertStatus(200);

        $component->assertDontSee(self::FOREIGN_N_CODE)
            ->assertDontSee('خارجی')
            ->assertSee('واحدی برای نمایش انتخاب نشده');

        $payload = $component->instance()->chartPayload();

        $this->assertSame(0, $payload['total'], 'the aggregate must fail closed too');
        $this->assertSame([], $payload['persons'], 'the roster must be empty');
        $this->assertSame([], $payload['byUnit'], 'the by-unit aggregate must be empty');
    }

    public function test_todos_report_leaks_nothing_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        $component = Livewire::test('reports.todos')->assertStatus(200);

        $component->assertDontSee(self::FOREIGN_TODO_TITLE)
            ->assertDontSee(self::FOREIGN_UNIT_NAME)
            ->assertSee('واحدی برای نمایش انتخاب نشده');

        $payload = $component->instance()->chartPayload();

        $this->assertSame([], $payload['items'], 'no todo row from any unit');
        $this->assertSame(0, $payload['completed'], 'the counts must not drift away from $items');
        $this->assertSame(0, $payload['pending']);
        $this->assertSame(0, $payload['overdue']);
        $this->assertSame([], $payload['byUnit']);
    }

    public function test_units_report_leaks_nothing_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        $component = Livewire::test('reports.units')->assertStatus(200);

        $component->assertDontSee(self::FOREIGN_UNIT_NAME)
            ->assertSee('واحدی برای نمایش انتخاب نشده');

        $payload = $component->instance()->chartPayload();

        $this->assertSame([], $payload['units'], 'the inventory list must be empty');
        $this->assertSame(0, $payload['total']);
        $this->assertSame(0, $payload['no_boundary']);
        $this->assertSame(0, $payload['with_boundary']);
        $this->assertSame([], $payload['byType']);
    }

    public function test_advanced_persons_aggregates_fail_closed_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'persons')
            ->assertStatus(200);

        $component->assertDontSee(self::FOREIGN_N_CODE)
            ->assertSee('واحدی برای نمایش انتخاب نشده');

        $data = $component->instance()->reportData();

        $this->assertSame(0, $data['total'], 'the scoped base query already fails closed');
        $this->assertSame([], $data['byUnit']);
        $this->assertSame([], $data['details']['byEstekhdam']);
        $this->assertSame([], $data['details']['byTahsil']);
        $this->assertSame([], $data['details']['bySemat']);
    }

    /**
     * Issue #915 — an empty scope plus a non-`all` status on `todos` must
     * not throw. The #819 fail-closed guard (`whereIn('unit_id', [])`) does
     * not shield this: Eloquent still compiles the `status` column reference
     * and Postgres answers 42703.
     */
    public function test_advanced_todos_status_filter_fails_closed_on_an_empty_scope(): void
    {
        $this->actingAsUserWithoutUnit();

        $component = Livewire::test('reports.advanced')
            ->set('reportType', 'todos')
            ->set('statusFilter', 'completed')
            ->assertStatus(200);

        $component->assertDontSee(self::FOREIGN_TODO_TITLE)
            ->assertSee('واحدی برای نمایش انتخاب نشده');

        $data = $component->instance()->reportData();

        $this->assertSame(0, $data['total'], 'the scoped base query already fails closed');
        $this->assertSame([], $data['byUnit']);
    }
}
