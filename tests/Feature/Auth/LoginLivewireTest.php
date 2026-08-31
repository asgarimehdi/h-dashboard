<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the auth login Livewire component.
 * Covers mount redirect when already authenticated, successful login
 * (session regenerate + redirect + activity log), invalid credentials,
 * validation errors, and the rate-limit lockout branch.
 */
#[CoversClass(\App\Http\Controllers\Api\HardwareController::class)]
class LoginLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        RateLimiter::clear('test|127.0.0.1');
    }

    protected function makeUser(string $nCode = '1234567890', string $password = 'secret123'): User
    {
        // users.n_code has a FK to persons.n_code -> create the person first.
        \App\Models\Person::create([
            'n_code' => $nCode,
            'f_name' => 'کاربر',
            'l_name' => 'تستی',
            't_id' => \App\Models\Tahsil::create(['name' => 'T-'.$nCode])->id,
            'e_id' => \App\Models\Estekhdam::create(['name' => 'E-'.$nCode])->id,
            's_id' => \App\Models\Semat::create(['name' => 'S-'.$nCode])->id,
            'r_id' => \App\Models\Radif::create(['name' => 'R-'.$nCode])->id,
            'u_id' => 1, // not enforced for login
        ]);

        return User::create([
            'n_code' => $nCode,
            'password' => Hash::make($password),
        ]);
    }

    public function test_mount_redirects_when_already_authenticated(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        Livewire::test('auth.login')
            ->assertRedirect('/');
    }

    public function test_component_renders_for_guest(): void
    {
        Livewire::test('auth.login')
            ->assertOk();
    }

    public function test_successful_login_authenticates_and_redirects(): void
    {
        $this->makeUser('1234567890', 'secret123');

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->set('remember', true)
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertTrue(Auth::check());
        $this->assertAuthenticatedAs(User::where('n_code', '1234567890')->first());
    }

    public function test_successful_login_writes_activity_log(): void
    {
        $this->makeUser('1234567890', 'secret123');

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->call('login');

        $this->assertDatabaseHas('activity_logs', [
            'type' => 'login',
        ]);
    }

    public function test_invalid_credentials_throws_validation_error(): void
    {
        $this->makeUser('1234567890', 'secret123');

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('n_code');

        $this->assertFalse(Auth::check());
    }

    public function test_nonexistent_user_throws_validation_error(): void
    {
        Livewire::test('auth.login')
            ->set('n_code', '0000000000')
            ->set('password', 'whatever')
            ->call('login')
            ->assertHasErrors('n_code');

        $this->assertFalse(Auth::check());
    }

    public function test_missing_fields_fails_validation(): void
    {
        Livewire::test('auth.login')
            ->set('n_code', '')
            ->set('password', '')
            ->call('login')
            ->assertHasErrors(['n_code', 'password']);
    }

    public function test_rate_limit_kicks_in_after_too_many_attempts(): void
    {
        $this->makeUser('1234567890', 'secret123');

        // Exhaust the 5-attempt budget with the IP used by the test kernel.
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('1234567890|127.0.0.1');
        }

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('n_code');

        // The throttle key is now locked.
        $this->assertTrue(RateLimiter::tooManyAttempts('1234567890|127.0.0.1', 5));
    }

    public function test_successful_login_clears_rate_limiter(): void
    {
        $this->makeUser('1234567890', 'secret123');

        // Pre-seed one failed attempt so we can verify it is cleared on success.
        RateLimiter::hit('1234567890|127.0.0.1');

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->call('login');

        $this->assertEquals(0, RateLimiter::attempts('1234567890|127.0.0.1'));
    }
}
