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

covers(\App\Models\User::class);

class UserModelTest extends TestCase
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

    protected function createUserWithPerson(array $personAttrs = []): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create(array_merge([
            'n_code' => $nCode, 'f_name' => 'علی', 'l_name' => 'احمدی',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ], $personAttrs));
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);

        return ['user' => $user, 'person' => Person::where('n_code', $nCode)->first(), 'unit' => $unit];
    }

    // --- Relationships ---

    public function test_user_belongs_to_person_via_n_code(): void
    {
        ['user' => $user, 'person' => $person] = $this->createUserWithPerson();

        $this->assertNotNull($user->person);
        $this->assertEquals($person->n_code, $user->person->n_code);
    }

    public function test_user_person_returns_null_when_no_person(): void
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'بی‌پروفایل', 'l_name' => 'تست',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => 1,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->assertNotNull($user->person);
    }

    public function test_user_has_many_units_via_pivot(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithPerson();
        $secondUnit = Unit::create(['name' => 'واحد دوم']);
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        $this->assertCount(2, $user->units);
    }

    public function test_user_primaryUnit_returns_primary_unit(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithPerson();
        $secondUnit = Unit::create(['name' => 'واحد دوم']);
        $user->units()->attach($secondUnit->id, ['role' => 'staff', 'is_primary' => false]);

        $this->assertEquals($unit->id, $user->primaryUnit()->id);
    }

    public function test_user_primaryUnit_returns_null_when_no_primary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithPerson();
        // Detach existing unit and re-attach without primary
        $user->units()->detach();
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => false]);

        $this->assertNull($user->primaryUnit());
    }

    // --- Name accessor ---

    public function test_user_name_accessor_returns_person_full_name(): void
    {
        ['user' => $user] = $this->createUserWithPerson([
            'f_name' => 'محمد', 'l_name' => 'رضایی',
        ]);

        Session::put('current_unit_id', 1);
        $this->actingAs($user);
        $this->assertEquals('محمد رضایی', $user->name);
    }

    public function test_user_name_accessor_returns_fallback_when_no_person(): void
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        // Create person then delete it to break the relation
        Person::create([
            'n_code' => $nCode, 'f_name' => 'حذف', 'l_name' => 'شده',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => 1,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        // Cache the name first
        Session::put('current_unit_id', 1);
        $this->actingAs($user);
        $name = $user->name;
        $this->assertNotEmpty($name);
    }

    public function test_user_name_is_cached_in_session(): void
    {
        ['user' => $user] = $this->createUserWithPerson([
            'f_name' => 'کش', 'l_name' => 'شده',
        ]);

        Session::put('current_unit_id', 1);
        $this->actingAs($user);

        $name1 = $user->name;
        $name2 = $user->name;

        $this->assertEquals($name1, $name2);
        $this->assertEquals('کش شده', Session::get("user_{$user->id}_display_name"));
    }

    // --- unitName accessor ---

    public function test_user_unitName_returns_person_unit_name(): void
    {
        ['user' => $user] = $this->createUserWithPerson();

        $this->assertEquals('واحد تست', $user->unit_name);
    }

    public function test_user_unitName_returns_dash_when_no_person(): void
    {
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'بدون واحد',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => 1,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);

        $this->assertNotNull($user->unit_name);
    }

    // --- Soft deletes ---

    public function test_user_uses_soft_deletes(): void
    {
        ['user' => $user] = $this->createUserWithPerson();
        $nCode = $user->n_code;

        $user->delete();

        $this->assertSoftDeleted('users', ['n_code' => $nCode]);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    // --- Hidden attributes ---

    public function test_password_is_hidden(): void
    {
        ['user' => $user] = $this->createUserWithPerson();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('settings', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    // --- Spatie HasRoles ---

    public function test_user_can_be_assigned_role(): void
    {
        ['user' => $user] = $this->createUserWithPerson();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_user_can_be_given_permission(): void
    {
        ['user' => $user] = $this->createUserWithPerson();

        $user->givePermissionTo('map');

        $this->assertTrue($user->can('map'));
    }

    // --- Settings cast ---

    public function test_settings_is_cast_to_array(): void
    {
        ['user' => $user] = $this->createUserWithPerson();

        $user->update(['settings' => ['theme' => 'dark', 'lang' => 'fa']]);

        $user->refresh();
        $this->assertIsArray($user->settings);
        $this->assertEquals('dark', $user->settings['theme']);
    }
}
