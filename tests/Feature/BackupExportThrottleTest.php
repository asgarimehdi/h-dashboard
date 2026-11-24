<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithBackupFiles;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1712 — the three web exports and the two backup downloads each build a whole
 * table (or the whole database) before responding. One shared `web-downloads`
 * budget (10/min per user) caps that.
 */
#[CoversNothing]
class BackupExportThrottleTest extends TestCase
{
    use InteractsWithBackupFiles;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const LIMIT = 10;

    protected function setUp(): void
    {
        parent::setUp();

        // tests/TestCase.php swaps ThrottleRequests for a no-op so bursts of
        // requests cannot 429 the suite (#705). This class asserts the limiter
        // itself, so restore the real middleware for this class.
        $this->app->forgetInstance(ThrottleRequests::class);

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();

        // Backups write into storage/app/private/backup; isolate so parallel
        // workers do not race on that directory.
        $this->useIsolatedBackupStorage();
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedBackupStorage();

        parent::tearDown();
    }

    public function test_every_heavy_download_route_carries_the_named_throttle(): void
    {
        $names = [
            'hardware.export',
            'units.export',
            'kargozini.persons.export',
            'backup.full',
            'backup.table',
        ];

        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is not registered.");
            $this->assertContains(
                'throttle:web-downloads',
                $route->gatherMiddleware(),
                "Route [{$name}] must carry throttle:web-downloads."
            );
        }
    }

    public function test_hardware_export_allows_ten_hits_then_429s(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $url = route('hardware.export', ['columns' => 'n_code,pc_name']);

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $this->get($url)->assertOk()->assertDownload();
        }

        $this->get($url)
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_backup_group_shares_one_counter_so_full_is_throttled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // The cheaper table download spends the same group budget.
        for ($i = 1; $i <= self::LIMIT; $i++) {
            $this->get(route('backup.table', 'hardwares'))->assertOk()->assertDownload();
        }

        // `full` was never called, but the group budget is already spent.
        $this->get(route('backup.full'))->assertStatus(429);
    }

    public function test_budget_returns_after_the_decay_minute(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['manage_hardware']);
        $this->actingAs($user);

        $url = route('hardware.export', ['columns' => 'n_code,pc_name']);

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $this->get($url)->assertOk();
        }

        $this->get($url)->assertStatus(429);

        $this->travel(61)->seconds();

        $this->get($url)->assertOk()->assertDownload();
    }

    /**
     * The declaration order is auth, role, throttle, but Kernel::$middlewarePriority
     * moves ThrottleRequests above the non-priority Spatie RoleMiddleware. So an
     * authenticated non-admin is denied (403) until their own budget is spent, then
     * capped (429) — the download never runs either way, and no other user's
     * counter is touched because the key is the caller's user id.
     */
    public function test_non_admin_is_forbidden_and_their_own_budget_is_capped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $this->get(route('backup.full'))->assertForbidden();
        }

        $this->get(route('backup.full'))->assertStatus(429);
    }

    public function test_guest_is_redirected_to_login_not_throttled(): void
    {
        $url = route('hardware.export', ['columns' => 'n_code,pc_name']);

        for ($i = 1; $i <= self::LIMIT + 1; $i++) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
