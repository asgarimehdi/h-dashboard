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

class ChangePasswordTest extends TestCase
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

    public function test_change_password_page_loads(): void
    {
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->assertStatus(200);
    }

    public function test_change_password_success(): void
    {
        $user = $this->createUserWithUnit();
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
        $user = $this->createUserWithUnit();
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
        $user = $this->createUserWithUnit();
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
        $user = $this->createUserWithUnit();
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
        $user = $this->createUserWithUnit();
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
        $user = $this->createUserWithUnit();
        $this->actingAs($user);

        Livewire::test('auth.changepassword')
            ->call('changePassword')
            ->assertHasErrors(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
    }
}
