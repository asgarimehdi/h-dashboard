<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

covers('auth.register');

class AuthRegisterLivewireTest extends TestCase
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

        foreach (['tahsils', 'estekhdams', 'semats', 'radifs'] as $table) {
            DB::unprepared(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT COALESCE(MAX(id),1) FROM {$table}))"
            );
        }
    }

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

    protected function createPerson(string $nCode): Person
    {
        $unit = Unit::firstOrCreate(['name' => 'واحد ثبت‌نام']);

        return Person::create([
            'n_code' => $nCode,
            'f_name' => 'ثبت',
            'l_name' => 'نام',
            't_id' => 1,
            'e_id' => 1,
            's_id' => 1,
            'r_id' => 1,
            'u_id' => $unit->id,
        ]);
    }

    // ==================== Guest renders ====================

    public function test_guest_renders(): void
    {
        Livewire::test('auth.register')
            ->assertStatus(200)
            ->assertSee('ثبت نام')
            ->assertSee('کدملی')
            ->assertSee('رمز عبور')
            ->assertSee('تکرار رمز عبور');
    }

    public function test_guest_sees_three_fields(): void
    {
        Livewire::test('auth.register')
            ->assertStatus(200)
            ->assertSeeHtml('wire:model="n_code"')
            ->assertSeeHtml('wire:model="password"')
            ->assertSeeHtml('wire:model="password_confirmation"');
    }

    // ==================== Authenticated mount redirect ====================

    public function test_authed_redirects(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.register')
            ->assertRedirect('/');
    }

    // ==================== Validation errors ====================

    public function test_validation_errors_empty_fields(): void
    {
        Livewire::test('auth.register')
            ->call('register')
            ->assertHasErrors(['n_code', 'password']);
    }

    public function test_validation_errors_n_code_not_size_10(): void
    {
        Livewire::test('auth.register')
            ->set('n_code', '123')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['n_code']);
    }

    public function test_validation_errors_password_mismatch(): void
    {
        Livewire::test('auth.register')
            ->set('n_code', '1234567890')
            ->set('password', 'password1')
            ->set('password_confirmation', 'password2')
            ->call('register')
            ->assertHasErrors(['password']);
    }

    // ==================== Person missing ====================

    public function test_person_missing(): void
    {
        Livewire::test('auth.register')
            ->set('n_code', '9999999999')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['n_code'])
            ->assertSee('کد ملی در سیستم ثبت نشده است');
    }

    // ==================== User duplicate ====================

    public function test_user_duplicate(): void
    {
        $nCode = '1122334455';
        $this->createPerson($nCode);

        // Create user manually (can't go through register() due to session issue)
        User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        Livewire::test('auth.register')
            ->set('n_code', $nCode)
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['n_code'])
            ->assertSee('این کد ملی قبلاً ثبت شده است');
    }
}
