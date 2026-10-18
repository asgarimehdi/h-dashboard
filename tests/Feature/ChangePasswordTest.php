<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(User::class);

class ChangePasswordTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Craft a logged-in web session under an explicit id in the array session
     * handler, so one test can act as two independent devices at the same time
     * (#1004). Returns the real session id — a 40-char alphanumeric string is
     * the only shape `Store::setId()` accepts — to send back with `withCookie`.
     */
    private function craftAuthenticatedSession(string $id, User $user): string
    {
        // Store::setId() regenerates the id when it is not exactly 40
        // alphanumeric characters, which would silently give both "devices" a
        // fresh random session.
        $id = str_pad($id, 40, 'x');

        $store = $this->app['session']->driver();
        $store->setId($id);
        $store->start();

        foreach (array_keys($store->all()) as $key) {
            $store->forget($key);
        }

        $store->put('login_web_'.sha1(SessionGuard::class), $user->id);
        $store->put('password_hash_web', $user->password);
        $store->put('current_unit_id', $user->units()->first()->id);
        $store->put('_token', 'token-'.$id);
        $store->save();

        return $id;
    }

    /**
     * Send an authenticated GET as the given crafted session. A real HTTP
     * request boots a fresh auth guard, but the test app keeps the guard
     * singleton alive — so reset it first, or the `loggedOut` flag set when a
     * previous request ended another session leaks into this one (#1004).
     */
    private function getAsSession(string $uri, string $sessionId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withCookie(config('session.cookie'), $sessionId)->get($uri);
    }

    /**
     * Change the password through the real HTTP Livewire update endpoint, so
     * the `web` middleware group (including AuthenticateSession) actually runs
     * over a request. `Livewire::test()` bypasses HTTP middleware, so it cannot
     * observe session invalidation (#1004).
     */
    private function changePasswordOverHttp(string $sessionId, string $current, string $new): void
    {
        $this->app['auth']->forgetGuards();

        $snapshot = Livewire::test('auth.changepassword')->snapshot;
        $snapshot = is_string($snapshot) ? $snapshot : json_encode($snapshot);

        $this->withCredentials()
            ->withCookie(config('session.cookie'), $sessionId)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(route('default-livewire.update'), [
                '_token' => 'token-'.$sessionId,
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'currentPassword' => $current,
                        'newPassword' => $new,
                        'newPasswordConfirmation' => $new,
                    ],
                    'calls' => [['path' => '', 'method' => 'changePassword', 'params' => []]],
                ]],
            ])
            ->assertOk();
    }

    public function test_other_web_session_is_invalidated_on_password_change(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $user->createToken('test-token');

        // device-a is a second (e.g. stolen) session; device-b changes the password.
        $deviceA = $this->craftAuthenticatedSession('deviceA', $user);
        $deviceB = $this->craftAuthenticatedSession('deviceB', $user);

        // Both sessions are valid before the password change.
        $this->getAsSession('/dashboard', $deviceA)->assertOk();
        $this->getAsSession('/dashboard', $deviceB)->assertOk();

        $this->changePasswordOverHttp($deviceB, 'password', 'new-password-123');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The other device's session cookie is rejected on its next request…
        $this->getAsSession('/dashboard', $deviceA)->assertRedirect('/login');

        // …while the device that changed the password stays signed in.
        $this->getAsSession('/dashboard', $deviceB)->assertOk();
    }

    public function test_stale_session_password_hash_is_logged_out(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        // A session whose stored password hash no longer matches the account
        // (i.e. a session that outlived a password change) must be dropped.
        Session::put('password_hash_web', 'a-stale-hash');

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_change_password_page_loads(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->assertStatus(200);
    }

    public function test_change_password_success(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_change_password_fails_with_wrong_current(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'wrong-current')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword')
            ->assertHasErrors(['currentPassword']);
    }

    public function test_change_password_fails_when_new_matches_current(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'password')
            ->set('newPassword', 'password')
            ->set('newPasswordConfirmation', 'password')
            ->call('changePassword')
            ->assertHasErrors(['newPassword']);
    }

    public function test_change_password_fails_when_confirmation_mismatch(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'different-password')
            ->call('changePassword')
            ->assertHasErrors(['newPasswordConfirmation']);
    }

    public function test_change_password_fails_when_new_too_short(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'password')
            ->set('newPassword', 'short')
            ->set('newPasswordConfirmation', 'short')
            ->call('changePassword')
            ->assertHasErrors(['newPassword']);
    }

    public function test_change_password_validates_required_fields(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->call('changePassword')
            ->assertHasErrors(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
    }

    public function test_change_password_revokes_all_tokens(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $this->actingAs($user);

        // Create a token for the user
        $token = $user->createToken('test-token');
        $tokenId = $token->accessToken->id;

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $tokenId,
        ]);

        Livewire::test('auth.changepassword')
            ->set('currentPassword', 'password')
            ->set('newPassword', 'new-password-123')
            ->set('newPasswordConfirmation', 'new-password-123')
            ->call('changePassword');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }
}
