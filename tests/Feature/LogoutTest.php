<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class LogoutTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_logout_redirects_to_home(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
    }

    public function test_logout_invalidates_session(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    public function test_logout_creates_activity_log(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->actingAs($user)->post('/logout');

        $this->assertDatabaseHas('activity_logs', [
            'type' => 'logout',
            'user_id' => $user->id,
        ]);
    }

    public function test_guest_cannot_access_logout(): void
    {
        // Logout route is outside auth middleware but calls Auth::id()
        // Guest accessing logout should just redirect
        $response = $this->post('/logout');

        $response->assertRedirect('/');
    }

    // ──────────────────────────────────────────────
    // Map token revocation (issue #840)
    //
    // The map page's plaintext token lives in the rendered HTML, so logout must
    // revoke it. Only `name = 'map-dashboard'`: deleting every token would also
    // sign the `flutter-app` mobile token out, which is a separate decision.
    // ──────────────────────────────────────────────

    public function test_logout_revokes_map_dashboard_tokens(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['map']);

        $token = $user->createToken('map-dashboard', ['gis:read'])->plainTextToken;

        $this->assertSame(1, $user->tokens()->where('name', 'map-dashboard')->count());

        $this->actingAs($user)->post('/logout');

        $this->assertSame(0, $user->fresh()->tokens()->where('name', 'map-dashboard')->count());

        // The row is gone, so the leaked plaintext is dead — not merely expired.
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', explode('|', $token, 2)[1])]);
    }

    public function test_logout_keeps_other_tokens_alive(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $mobile = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;
        $user->createToken('map-dashboard', ['gis:read']);

        $this->actingAs($user)->post('/logout');

        $this->assertSame(1, $user->fresh()->tokens()->where('name', 'flutter-app')->count());
        $this->assertSame(0, $user->fresh()->tokens()->where('name', 'map-dashboard')->count());

        // The mobile token still authenticates after a browser logout.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$mobile,
            'Accept' => 'application/json',
        ])->getJson('/api/notifications')->assertOk();
    }

    public function test_logout_revokes_map_dashboard_token_by_the_name_the_component_mints(): void
    {
        // Pins the two literals against each other: if the component's
        // TOKEN_NAME ever changes, this fails instead of the route silently
        // revoking nothing.
        ['user' => $user] = $this->createUserWithUnit(['map']);

        Livewire::actingAs($user)->test('map.map-dashboard');

        $this->assertSame(1, $user->tokens()->where('name', 'map-dashboard')->count());

        $this->actingAs($user)->post('/logout');

        $this->assertSame(0, $user->fresh()->tokens()->where('name', 'map-dashboard')->count());
    }
}
