<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Traits\HasOrganizationalScope;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(HasOrganizationalScope::class);

class HasOrganizationalScopeTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_accessible_scope_filters_tickets_by_unit(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user] = $this->createUserWithUnit();
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
        ['user' => $user] = $this->createUserWithUnit();
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
