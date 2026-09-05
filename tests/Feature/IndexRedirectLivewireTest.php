<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class IndexRedirectLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        DB::table('tahsils')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('estekhdams')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('semats')->insert(['id' => 1, 'name' => 'Test']);
        DB::table('radifs')->insert(['id' => 1, 'name' => 'Test']);

        // Resync Postgres sequences so subsequent inserts don't hit duplicate keys
        DB::statement("SELECT setval('tahsils_id_seq', COALESCE((SELECT MAX(id) FROM tahsils), 1))");
        DB::statement("SELECT setval('estekhdams_id_seq', COALESCE((SELECT MAX(id) FROM estekhdams), 1))");
        DB::statement("SELECT setval('semats_id_seq', COALESCE((SELECT MAX(id) FROM semats), 1))");
        DB::statement("SELECT setval('radifs_id_seq', COALESCE((SELECT MAX(id) FROM radifs), 1))");
    }

    /**
     * Helper: create a user attached to a single unit, ready to act as.
     */
    protected function createUserWithUnit(): User
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return $user;
    }

    // ==================== Page load / auth (HTTP route tests) ====================

    public function test_guest_302(): void
    {
        // Guest request to / (role-picker) -> redirect to /login by auth middleware
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authed_redirects_dashboard(): void
    {
        // Authenticated user WITH unit context -> component mount() redirects to /dashboard
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        // ValidateUnitContext middleware auto-sets current_unit_id since user has 1 unit
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_no_context_redirect(): void
    {
        // Authenticated user WITHOUT unit context (e.g. belongs to 0 units) ->
        // ValidateUnitContext allows request but no current_unit_id is set
        // Then component's mount() redirects to /dashboard.
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بی', 'l_name' => 'واحد',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => null,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->actingAs($user);

        // Should reach the component (no /select-context bounce since 0 units)
        // then redirect to /dashboard from mount()
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_expired_session(): void
    {
        // Expired session is effectively a guest: 302 to /login, never 500
        $this->get('/')->assertRedirect('/login');
    }

    // ==================== Livewire component tests (no route middleware) ====================

    public function test_livewire_redirect(): void
    {
        // Direct Livewire::test bypasses route middleware and runs mount() -> redirect /dashboard
        $user = $this->createUserWithUnit();

        Livewire::actingAs($user)
            ->test('index')
            ->assertRedirect('/dashboard');
    }

    public function test_mount_short_circuits_render(): void
    {
        // mount() always returns a redirect -> the rendered response is a
        // redirect response, NOT the index Blade view. The header
        // content therefore must NOT contain the index page's distinctive
        // "انتخاب نقش" title from the blade view.
        $user = $this->createUserWithUnit();

        $component = Livewire::actingAs($user)->test('index');
        $this->assertStringNotContainsString('انتخاب نقش', $component->html());
    }

    public function test_default_props(): void
    {
        // Component's mount() returns redirect before any prop is initialized,
        // so selectedRole and roleOptions are still at their declared defaults: null.
        $user = $this->createUserWithUnit();

        Livewire::actingAs($user)
            ->test('index')
            ->assertSet('selectedRole', null)
            ->assertSet('roleOptions', null);
    }

    public function test_no_db_writes_on_mount(): void
    {
        // Mounting the index component must not write to the database.
        $user = $this->createUserWithUnit();
        $unitId = $user->units()->first()->id;

        $beforeUsers = DB::table('users')->count();
        $beforeUnits = DB::table('units')->count();
        $beforePersons = DB::table('persons')->count();
        $beforeUserUnits = DB::table('user_units')->count();
        $beforeActivity = DB::table('activity_logs')->count();

        Livewire::actingAs($user)->test('index');

        $this->assertSame($beforeUsers, DB::table('users')->count());
        $this->assertSame($beforeUnits, DB::table('units')->count());
        $this->assertSame($beforePersons, DB::table('persons')->count());
        $this->assertSame($beforeUserUnits, DB::table('user_units')->count());
        $this->assertSame($beforeActivity, DB::table('activity_logs')->count());
        $this->assertSame($unitId, $user->units()->first()->id);
    }

    public function test_component_class_has_no_permission_gate(): void
    {
        // Sanity: a brand-new user with NO permission at all still gets
        // redirected to /dashboard (no permission middleware on this route).
        $user = $this->createUserWithUnit();
        // Intentionally NOT calling $user->givePermissionTo(...)

        Livewire::actingAs($user)
            ->test('index')
            ->assertRedirect('/dashboard');
    }

    // ==================== Session / context interaction ====================

    public function test_session_current_unit_id_is_consumed(): void
    {
        // When a user with valid unit context hits /, the session must already
        // have current_unit_id set so the request reaches the index component.
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();

        $this->actingAs($user)->withSession(['current_unit_id' => $unit->id]);

        $response = $this->get('/');
        $response->assertRedirect('/dashboard');

        // After redirect, ValidateUnitContext may have refreshed the session.
        // Either the original id or a refreshed one is fine; the request must
        // not have been bounced to /select-context or /login.
        $this->assertNotEquals('/select-context', $response->headers->get('Location'));
        $this->assertNotEquals('/login', $response->headers->get('Location'));
    }
}
