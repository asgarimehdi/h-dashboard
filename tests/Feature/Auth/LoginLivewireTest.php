<?php

namespace Tests\Feature\Auth;

use App\Models\Estekhdam;
use App\Models\Person;
use App\Models\Radif;
use App\Models\Semat;
use App\Models\Tahsil;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the auth login Livewire component.
 * Covers mount redirect when already authenticated, successful login
 * (session regenerate + redirect + activity log), invalid credentials,
 * validation errors, and the rate-limit lockout branch.
 */
covers(User::class);

class LoginLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        RateLimiter::clear($this->ipKey());
        RateLimiter::clear($this->accountKey());
    }

    /**
     * Per-account+IP throttle key, mirroring production normalization.
     */
    private function ipKey(string $nCode = '1234567890'): string
    {
        return 'login-ip:'.hash('sha256', Str::transliterate(Str::lower($nCode).'|127.0.0.1'));
    }

    /**
     * Per-account throttle key, independent of the source IP.
     */
    private function accountKey(string $nCode = '1234567890'): string
    {
        return 'login-account:'.hash('sha256', Str::transliterate(Str::lower($nCode)));
    }

    protected function makeUser(string $nCode = '1234567890', string $password = 'secret123'): User
    {
        // users.n_code has a FK to persons.n_code -> create the person first.
        $unit = Unit::create(['name' => 'Test Unit']);
        Person::create([
            'n_code' => $nCode,
            'f_name' => 'کاربر',
            'l_name' => 'تستی',
            't_id' => Tahsil::create(['name' => 'T-'.$nCode])->id,
            'e_id' => Estekhdam::create(['name' => 'E-'.$nCode])->id,
            's_id' => Semat::create(['name' => 'S-'.$nCode])->id,
            'r_id' => Radif::create(['name' => 'R-'.$nCode])->id,
            'u_id' => $unit->id,
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
            RateLimiter::hit($this->ipKey());
        }

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('n_code');

        // The throttle key is now locked.
        $this->assertTrue(RateLimiter::tooManyAttempts($this->ipKey(), 5));
    }

    public function test_successful_login_clears_both_throttle_budgets(): void
    {
        $this->makeUser('1234567890', 'secret123');

        // Pre-seed one failed attempt on each budget so we can verify both are cleared.
        RateLimiter::hit($this->ipKey());
        RateLimiter::hit($this->accountKey());

        Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'secret123')
            ->call('login');

        $this->assertSame(0, RateLimiter::attempts($this->ipKey()));
        $this->assertSame(0, RateLimiter::attempts($this->accountKey()));
    }

    public function test_account_budget_survives_source_ip_rotation(): void
    {
        $this->makeUser('1234567890', 'secret123');

        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.login')
                ->set('n_code', '1234567890')
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors('n_code');
        }

        // A brand-new source IP: its per-IP key starts empty. The account budget must not.
        RateLimiter::clear($this->ipKey());

        $component = Livewire::test('auth.login')
            ->set('n_code', '1234567890')
            ->set('password', 'wrong-password')
            ->call('login');

        $this->assertNotSame('نام کاربری یا رمز عبور اشتباه است', (string) $component->errors()->first('n_code'));
        $this->assertSame(5, RateLimiter::attempts($this->accountKey())); // blocked before hit()
        $this->assertSame(0, RateLimiter::attempts($this->ipKey()));
    }

    public function test_one_accounts_failures_do_not_lock_another_account(): void
    {
        $this->makeUser('1234567890', 'secret123');
        $this->makeUser('0987654321', 'secret456');

        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.login')
                ->set('n_code', '1234567890')
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors('n_code');
        }

        // Fresh source IP; the other account must still be able to log in.
        RateLimiter::clear($this->ipKey());

        Livewire::test('auth.login')
            ->set('n_code', '0987654321')
            ->set('password', 'secret456')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertTrue(Auth::check());
    }
}
