<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
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
 * Issue #834 — `activity-log` scoped its list but not its detail.
 *
 * `logs()` and `getTypeStats()` both filter with
 * `->whereIn('user_id', $accessibleUserIds)`, while `showDetail($id)` was a bare
 * `ActivityLog::findOrFail($id)`. It is a public Livewire method, so any
 * authenticated `manage_users` holder could call it with an arbitrary (sequential)
 * log id and read another unit's audit row — acting user's name, `ip_address`,
 * `description`, `old_values`/`new_values` and `user_agent`.
 *
 * These tests pin the fail-closed detail contract: "no data, no modal".
 */
#[CoversNothing]
class ActivityLogDetailScopeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const FOREIGN_DESCRIPTION = 'تغییر رمز کارمند واحد بیرونی';

    private const OWN_DESCRIPTION = 'ویرایش پروفایل کاربر';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * A caller with `manage_users` in unit A, plus one foreign unit's user and
     * audit rows that must stay unreadable.
     */
    private function actingAsScopedUserWithForeignLogs(): array
    {
        $unitA = Unit::factory()->create();
        $personA = Person::factory()->create(['u_id' => $unitA->id]);
        $caller = User::factory()->create(['n_code' => $personA->n_code]);
        $caller->givePermissionTo('manage_users');
        $caller->units()->attach($unitA->id, ['role' => 'staff', 'is_primary' => true]);
        session(['current_unit_id' => $unitA->id]);

        $unitB = Unit::factory()->create();
        $personB = Person::factory()->create(['u_id' => $unitB->id]);
        $foreignUser = User::factory()->create(['n_code' => $personB->n_code]);
        $foreignUser->units()->attach($unitB->id, ['role' => 'staff', 'is_primary' => true]);

        $foreignLog = ActivityLog::create([
            'user_id' => $foreignUser->id,
            'type' => 'updated',
            'description' => self::FOREIGN_DESCRIPTION,
            'old_values' => ['password' => 'قدیم'],
            'new_values' => ['password' => 'جدید'],
            'ip_address' => '10.20.30.40',
            'user_agent' => 'ForeignAgent',
        ]);

        $ownLog = ActivityLog::create([
            'user_id' => $caller->id,
            'type' => 'updated',
            'description' => self::OWN_DESCRIPTION,
            'old_values' => ['name' => 'قدیم'],
            'new_values' => ['name' => 'جدید'],
            'ip_address' => '192.168.1.1',
        ]);

        $this->actingAs($caller);

        // Guard the fixture: a caller that cannot see unit B is what makes the
        // assertions below meaningful.
        $accessible = app(AccessService::class)->accessibleUnitIds();
        $this->assertContains($unitA->id, $accessible, 'the fixture caller must see its own unit');
        $this->assertNotContains($unitB->id, $accessible, 'the fixture caller must NOT see the foreign unit');

        return [$caller, $ownLog, $foreignLog];
    }

    /**
     * A caller with `manage_users` but no unit assignment at all — the empty
     * scope case, which is reachable because `ValidateUnitContext` deliberately
     * lets such accounts continue.
     */
    private function actingAsUserWithoutUnit(): ActivityLog
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بدون واحد', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);

        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->givePermissionTo('manage_users');

        $foreignUnit = Unit::factory()->create();
        $foreignPerson = Person::factory()->create(['u_id' => $foreignUnit->id]);
        $foreignUser = User::factory()->create(['n_code' => $foreignPerson->n_code]);
        $foreignUser->units()->attach($foreignUnit->id, ['role' => 'staff', 'is_primary' => true]);

        $foreignLog = ActivityLog::create([
            'user_id' => $foreignUser->id,
            'type' => 'login',
            'description' => 'ورود کاربر واحد بیرونی',
        ]);

        $this->actingAs($user);

        $this->assertSame([], app(AccessService::class)->accessibleUnitIds(), 'the fixture must really be an empty scope');

        return $foreignLog;
    }

    public function test_detail_of_another_units_log_is_not_reachable(): void
    {
        [, , $foreignLog] = $this->actingAsScopedUserWithForeignLogs();

        $component = Livewire::test('activity-log.index');

        // Livewire 4 swallows the ModelNotFoundException from a scoped
        // findOrFail, so the call returns normally with the modal untouched.
        // The contract is "no data, no modal", not a specific status code.
        $component->call('showDetail', $foreignLog->id)
            ->assertSet('showModal', false)
            ->assertSet('selectedLog', null)
            ->assertDontSee(self::FOREIGN_DESCRIPTION)
            ->assertDontSee('10.20.30.40')
            ->assertDontSee('ForeignAgent');
    }

    public function test_detail_of_another_units_log_is_not_reachable_with_an_empty_scope(): void
    {
        $foreignLog = $this->actingAsUserWithoutUnit();

        $component = Livewire::test('activity-log.index');

        $component->call('showDetail', $foreignLog->id)
            ->assertSet('showModal', false)
            ->assertSet('selectedLog', null)
            ->assertDontSee(self::FOREIGN_DESCRIPTION);
    }

    public function test_detail_of_a_log_without_any_person_record_is_not_reachable(): void
    {
        // A log whose user has no `person` row is invisible in the scoped list
        // (not in getAccessibleUserIds()), so the scoped detail must match it.
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بی‌شخص', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);
        $personlessUser = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $personlessUser->givePermissionTo('manage_users');

        $caller = User::factory()->create();
        $caller->givePermissionTo('manage_users');

        $orphanLog = ActivityLog::create([
            'user_id' => $personlessUser->id,
            'type' => 'login',
            'description' => 'سطر بدون رکورد شخص',
        ]);

        $this->actingAs($caller);

        $component = Livewire::test('activity-log.index');

        $component->call('showDetail', $orphanLog->id)
            ->assertSet('showModal', false)
            ->assertSet('selectedLog', null)
            ->assertDontSee('سطر بدون رکورد شخص');
    }

    public function test_detail_of_an_in_scope_log_still_opens(): void
    {
        [, $ownLog] = $this->actingAsScopedUserWithForeignLogs();

        Livewire::test('activity-log.index')
            ->call('showDetail', $ownLog->id)
            ->assertSet('showModal', true)
            ->assertSet('selectedLog.id', $ownLog->id)
            ->assertSee(self::OWN_DESCRIPTION)
            ->assertSee('192.168.1.1');
    }
}
