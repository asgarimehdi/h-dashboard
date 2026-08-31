<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

#[CoversClass(\App\Traits\HasOrganizationalScope::class)]

class HasOrganizationalScopeTest extends TestCase
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

    public function test_accessible_scope_filters_tickets_by_unit(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        Session::put('current_unit_id', $unit->id);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        $myTicket = Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت من',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        Ticket::create([
            'ticket_code' => 'TKT-002',
            'user_id' => $user->id,
            'unit_id' => $otherUnit->id,
            'subject' => 'تیکت دیگری',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $this->actingAs($user);
        $tickets = Ticket::accessible()->get();

        $this->assertCount(1, $tickets);
        $this->assertEquals($myTicket->id, $tickets->first()->id);
    }

    public function test_accessible_scope_filters_todos_by_unit(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        Session::put('current_unit_id', $unit->id);

        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        Todo::factory()->create(['unit_id' => $unit->id, 'title' => 'وظیفه من']);
        Todo::factory()->create(['unit_id' => $otherUnit->id, 'title' => 'وظیفه دیگری']);

        $this->actingAs($user);
        $todos = Todo::accessible()->get();

        $this->assertCount(1, $todos);
    }

    public function test_accessible_scope_includes_child_units(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        $childUnit = Unit::create(['name' => 'فرزند', 'parent_id' => $unit->id]);
        Session::put('current_unit_id', $unit->id);

        Todo::factory()->create(['unit_id' => $unit->id, 'title' => 'والد']);
        Todo::factory()->create(['unit_id' => $childUnit->id, 'title' => 'فرزند']);

        $this->actingAs($user);
        $todos = Todo::accessible()->get();

        $this->assertCount(2, $todos);
    }

    public function test_accessible_scope_excludes_other_branches(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        $otherUnit = Unit::create(['name' => 'شاخه دیگر']);
        Session::put('current_unit_id', $unit->id);

        Todo::factory()->create(['unit_id' => $unit->id, 'title' => 'واحد من']);
        Todo::factory()->create(['unit_id' => $otherUnit->id, 'title' => 'واحد دیگر']);

        $this->actingAs($user);
        $todos = Todo::accessible()->get();

        $this->assertCount(1, $todos);
    }

    public function test_accessible_scope_with_related_loading(): void
    {
        $user = $this->createUserWithUnit();
        $unit = $user->units()->first();
        Session::put('current_unit_id', $unit->id);

        Ticket::create([
            'ticket_code' => 'TKT-001',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $this->actingAs($user);
        $tickets = Ticket::accessible(unitColumn: 'unit_id', withRelated: true)->get();

        $this->assertCount(1, $tickets);
        $this->assertTrue($tickets->first()->relationLoaded('unit'));
    }
}
