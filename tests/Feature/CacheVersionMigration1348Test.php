<?php

namespace Tests\Feature;

use App\Console\Commands\PruneStaleCache;
use App\Jobs\CleanNotificationsJob;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Services\CacheInvalidationServiceInterface;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]

/**
 * Issue #1348 — the seven unversioned Cache::remember sites migrate to
 * versioned remember() reads, and every writer bumps the namespace it
 * feeds. Behavioural rule per site: after an effective write, the next
 * read serves fresh data.
 */
class CacheVersionMigration1348Test extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
        Cache::flush();
    }

    private function version(string $namespace): int
    {
        return app(CacheInvalidationServiceInterface::class)->getVersion($namespace);
    }

    // -----------------------------------------------------------
    // Registry
    // -----------------------------------------------------------

    public function test_new_namespaces_are_registered(): void
    {
        foreach (['activity_log', 'report_no_boundary', 'unit_types', 'tools_stats', 'notifications'] as $namespace) {
            $this->assertContains($namespace, PruneStaleCache::NAMESPACES);
        }
    }

    // -----------------------------------------------------------
    // Wiring: model events bump their namespaces
    // -----------------------------------------------------------

    public function test_unit_type_writes_bump_all_five_namespaces(): void
    {
        $before = [];
        foreach (['report_units', 'hr_stats', 'unit_hierarchy', 'report_no_boundary', 'unit_types'] as $ns) {
            $before[$ns] = $this->version($ns);
        }

        $type = UnitType::create(['name' => 'Type A']);

        foreach ($before as $ns => $v) {
            $this->assertSame($v + 1, $this->version($ns), "{$ns} must bump on UnitType create");
        }

        $type->update(['name' => 'Type A Renamed']);

        foreach ($before as $ns => $v) {
            $this->assertSame($v + 2, $this->version($ns), "{$ns} must bump on UnitType rename");
        }

        $type->delete();

        foreach ($before as $ns => $v) {
            $this->assertSame($v + 3, $this->version($ns), "{$ns} must bump on UnitType delete");
        }
    }

    public function test_region_writes_bump_report_no_boundary(): void
    {
        $before = $this->version('report_no_boundary');

        $region = Region::create(['name' => 'Region A', 'type' => 'province']);
        $this->assertSame($before + 1, $this->version('report_no_boundary'));

        $region->update(['name' => 'Region A Renamed']);
        $this->assertSame($before + 2, $this->version('report_no_boundary'));

        $region->delete();
        $this->assertSame($before + 3, $this->version('report_no_boundary'));
    }

    public function test_notification_writes_bump_notifications_and_tools_stats(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $beforeNotifications = $this->version('notifications');
        $beforeTools = $this->version('tools_stats');

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Hello',
        ]);
        $this->assertSame($beforeNotifications + 1, $this->version('notifications'));
        $this->assertSame($beforeTools + 1, $this->version('tools_stats'));

        // Query-builder path: no model event, explicit bump only.
        Notification::markAllAsRead();
        $this->assertSame($beforeNotifications + 2, $this->version('notifications'));

        // Instance update fires the `updated` hook.
        $notification->markAsRead();
        $this->assertSame($beforeNotifications + 3, $this->version('notifications'));
    }

    public function test_user_create_and_delete_bump_activity_log(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit();
        $person = Person::factory()->create(['u_id' => $unit->id]);

        $before = $this->version('activity_log');

        $user = User::create(['n_code' => $person->n_code, 'password' => Hash::make('password')]);
        $this->assertSame($before + 1, $this->version('activity_log'));

        $user->delete();
        $this->assertSame($before + 2, $this->version('activity_log'));
    }

    public function test_person_unit_move_bumps_activity_log_but_other_edits_do_not(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $otherUnit = Unit::factory()->create();
        $person = Person::query()->where('n_code', $user->n_code)->firstOrFail();

        $before = $this->version('activity_log');

        $person->update(['u_id' => $otherUnit->id]);
        $this->assertSame($before + 1, $this->version('activity_log'));

        $person->update(['f_name' => 'ChangedName']);
        $this->assertSame($before + 1, $this->version('activity_log'), 'A non-unit Person edit must not bump activity_log');

        $standalone = Person::factory()->create(['u_id' => $unit->id]);
        $afterCreate = $this->version('activity_log');
        $this->assertSame($before + 2, $afterCreate, 'Person creation bumps activity_log');

        $standalone->delete();
        $this->assertSame($before + 3, $this->version('activity_log'));
    }

    public function test_activity_log_create_bumps_activity_log_and_tools_stats(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $beforeActivity = $this->version('activity_log');
        $beforeTools = $this->version('tools_stats');

        ActivityLog::create(['user_id' => $user->id, 'type' => 'login', 'description' => 'test']);

        $this->assertSame($beforeActivity + 1, $this->version('activity_log'));
        $this->assertSame($beforeTools + 1, $this->version('tools_stats'));
    }

    public function test_clean_notifications_job_bumps_both_namespaces(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        // Query-builder insert: no model event, so only the job's explicit
        // bump may move the versions.
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Old',
            'is_read' => false,
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        $beforeNotifications = $this->version('notifications');
        $beforeTools = $this->version('tools_stats');

        $deleted = (new CleanNotificationsJob(days: 7))->handle();

        $this->assertSame(1, $deleted);
        $this->assertSame($beforeNotifications + 1, $this->version('notifications'));
        $this->assertSame($beforeTools + 1, $this->version('tools_stats'));
    }

    // -----------------------------------------------------------
    // Sites 1–2: activity log
    // -----------------------------------------------------------

    public function test_activity_log_type_stats_refresh_after_new_log(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        Livewire::test('activity-log.index')
            ->assertSet('typeStats', fn ($stats) => ! array_key_exists('fresh_type_1348', $stats));

        ActivityLog::create(['user_id' => $user->id, 'type' => 'fresh_type_1348', 'description' => 'test']);

        Livewire::test('activity-log.index')
            ->assertSet('typeStats', fn ($stats) => ($stats['fresh_type_1348'] ?? 0) === 1);
    }

    public function test_activity_log_type_stats_ttl_is_five_minutes(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->givePermissionTo('manage_users');
        $this->actingAs($user);

        Livewire::test('activity-log.index')
            ->assertSet('typeStats', fn ($stats) => ! array_key_exists('ttl_type_1348', $stats));

        // Query-builder write: no bump, so the cached read must stay stale
        // until the TTL lapses.
        DB::table('activity_logs')->insert([
            'user_id' => $user->id,
            'type' => 'ttl_type_1348',
            'description' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test('activity-log.index')
            ->assertSet('typeStats', fn ($stats) => ! array_key_exists('ttl_type_1348', $stats));

        try {
            Carbon::setTestNow(now()->addMinutes(6));

            Livewire::test('activity-log.index')
                ->assertSet('typeStats', fn ($stats) => ($stats['ttl_type_1348'] ?? 0) === 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    // -----------------------------------------------------------
    // Site 3: advanced report units
    // -----------------------------------------------------------

    public function test_advanced_report_units_refresh_after_unit_rename(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => collect($units)->contains('name', $unit->name));

        // Query-builder rename: no bump, the cached list stays stale.
        DB::table('units')->where('id', $unit->id)->update(['name' => 'StaleUnitName']);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => collect($units)->contains('name', $unit->name)
                && ! collect($units)->contains('name', 'StaleUnitName'));

        // Eloquent rename bumps `report_units`: the next read is fresh.
        $unit->update(['name' => 'FreshUnitName']);

        Livewire::test('reports.advanced')
            ->assertSet('units', fn ($units) => collect($units)->contains('name', 'FreshUnitName'));
    }

    // -----------------------------------------------------------
    // Site 4: no-boundary map
    // -----------------------------------------------------------

    public function test_no_boundary_map_refresh_after_region_rename(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        $this->actingAs($user);

        $region = Region::create(['name' => 'OldRegion', 'type' => 'province']);
        DB::table('units')->where('id', $unit->id)->update(['region_id' => $region->id, 'boundary_id' => null]);

        $names = fn () => collect(Livewire::test('reports.map-no-boundary')->instance()->getAllUnitsProperty())
            ->pluck('region')->all();

        $this->assertContains('OldRegion', $names());

        // Query-builder rename: no bump, the cached payload stays stale.
        DB::table('regions')->where('id', $region->id)->update(['name' => 'StaleRegion']);
        $this->assertContains('OldRegion', $names());
        $this->assertNotContains('StaleRegion', $names());

        // Eloquent rename bumps `report_no_boundary`: the next read is fresh.
        $region->update(['name' => 'FreshRegion']);
        $this->assertContains('FreshRegion', $names());
    }

    // -----------------------------------------------------------
    // Site 5: unit types list (24-hour TTL — the load-bearing rename)
    // -----------------------------------------------------------

    public function test_unit_types_list_refresh_after_rename(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        $type = UnitType::create(['name' => 'OldTypeName']);

        $names = fn () => Livewire::test('reports.units')->instance()->getUnitTypesProperty()->pluck('name')->all();

        $this->assertContains('OldTypeName', $names());

        // Query-builder rename: no bump, the cached list stays stale for the
        // full 24-hour TTL.
        DB::table('unit_types')->where('id', $type->id)->update(['name' => 'StaleTypeName']);
        $this->assertContains('OldTypeName', $names());
        $this->assertNotContains('StaleTypeName', $names());

        // Eloquent rename bumps `unit_types`: the next read is fresh.
        $type->update(['name' => 'FreshTypeName']);
        $this->assertContains('FreshTypeName', $names());
    }

    // -----------------------------------------------------------
    // Site 6: tools stats
    // -----------------------------------------------------------

    public function test_tools_stats_refresh_after_ticket_write(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['manage_users']);
        $this->actingAs($user);

        Livewire::test('tools.tools')
            ->assertSet('stats', fn ($stats) => $stats['total_tickets'] === 0);

        // Query-builder insert: no bump, the cached counts stay stale.
        $attrs = Ticket::factory()->make(['unit_id' => $unit->id])->getAttributes();
        DB::table('tickets')->insert($attrs);

        Livewire::test('tools.tools')
            ->assertSet('stats', fn ($stats) => $stats['total_tickets'] === 0);

        // Eloquent create bumps `tools_stats`: the next read is fresh.
        Ticket::factory()->create(['unit_id' => $unit->id]);

        Livewire::test('tools.tools')
            ->assertSet('stats', fn ($stats) => $stats['total_tickets'] === 2);
    }

    // -----------------------------------------------------------
    // Site 7: notifications bell (1-minute TTL)
    // -----------------------------------------------------------

    public function test_bell_shows_new_notification_after_send(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 0);

        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'Hello']);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 1);
    }

    public function test_bell_ttl_is_one_minute(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 0);

        // Query-builder insert: no bump, so the cached read must stay stale
        // until the TTL lapses.
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'test',
            'title' => 'Hello',
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test('notifications.bell')
            ->assertSet('unreadCount', 0);

        try {
            Carbon::setTestNow(now()->addMinutes(2));

            Livewire::test('notifications.bell')
                ->assertSet('unreadCount', 1);
        } finally {
            Carbon::setTestNow();
        }
    }
}
