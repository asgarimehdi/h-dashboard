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
use Tests\TestCase;

class LogoutTest extends TestCase
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

    public function test_logout_redirects_to_home(): void
    {
        $user = $this->createUserWithUnit();

        $response = $this->actingAs($user)->get('/logout');

        $response->assertRedirect('/');
    }

    public function test_logout_invalidates_session(): void
    {
        $user = $this->createUserWithUnit();

        $this->actingAs($user)->get('/logout');

        $this->assertGuest();
    }

    public function test_logout_creates_activity_log(): void
    {
        $user = $this->createUserWithUnit();

        $this->actingAs($user)->get('/logout');

        $this->assertDatabaseHas('activity_logs', [
            'type' => 'logout',
            'user_id' => $user->id,
        ]);
    }

    public function test_guest_cannot_access_logout(): void
    {
        // Logout route is outside auth middleware but calls Auth::id()
        // Guest accessing logout should just redirect
        $response = $this->get('/logout');

        $response->assertRedirect('/');
    }
}
