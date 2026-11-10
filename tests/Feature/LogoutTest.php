<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    // Logout must not sign the mobile app out
    //
    // The browser session ends, but `flutter-app` is a separate product
    // decision and its bearer token survives. (Issue #1399 removed the
    // per-page `map-dashboard` token entirely: the map authenticates with the
    // session cookie now, so there is nothing extra to revoke here.)
    // ──────────────────────────────────────────────

    public function test_logout_keeps_other_tokens_alive(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $mobile = $user->createToken('flutter-app', $user->getApiTokenAbilities())->plainTextToken;

        $this->actingAs($user)->post('/logout');

        $this->assertSame(1, $user->fresh()->tokens()->where('name', 'flutter-app')->count());

        // The mobile token still authenticates after a browser logout.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$mobile,
            'Accept' => 'application/json',
        ])->getJson('/api/notifications')->assertOk();
    }
}
