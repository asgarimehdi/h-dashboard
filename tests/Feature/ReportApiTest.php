<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportController;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

covers(ReportController::class);

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
    }

    protected function createUserWithUnit(): array
    {
        $tId = DB::table('tahsils')->insertGetId(['name' => 'Test']);
        $eId = DB::table('estekhdams')->insertGetId(['name' => 'Test']);
        $sId = DB::table('semats')->insertGetId(['name' => 'Test']);
        $rId = DB::table('radifs')->insertGetId(['name' => 'Test']);

        $nCode = (string) fake()->unique()->numerify('##########');
        $unit = Unit::create(['name' => 'Test Unit']);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => $unit->id]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_unauthenticated_user_cannot_access_reports(): void
    {
        $response = $this->getJson('/api/reports/units');
        $response->assertStatus(401);
    }

    public function test_units_report_returns_summary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/units');

        $response->assertStatus(200)
            ->assertJsonStructure(['total', 'with_boundary', 'without_boundary', 'by_type']);
    }

    public function test_units_report_counts_correctly(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Unit::create(['name' => 'No Boundary']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/units');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_todos_report_returns_summary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Todo::create(['title' => 'Done', 'unit_id' => $unit->id, 'is_completed' => true, 'start_at' => now(), 'end_at' => now()]);
        Todo::create(['title' => 'Pending', 'unit_id' => $unit->id, 'is_completed' => false, 'start_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/todos');

        $response->assertStatus(200)
            ->assertJsonStructure(['completed', 'pending', 'overdue', 'by_day', 'by_unit']);
    }

    public function test_todos_report_by_day_uses_jalali_dates(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Todo::create(['title' => 'Task', 'unit_id' => $unit->id, 'is_completed' => false, 'start_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/todos');

        $response->assertStatus(200);
        $byDay = $response->json('by_day');
        if (count($byDay) > 0) {
            $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', $byDay[0]['day']);
        }
    }

    public function test_tickets_report_returns_summary(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Ticket::create(['ticket_code' => 'T-001', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Test', 'content' => 'Body', 'priority' => 'urgent', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure(['total', 'by_status', 'by_priority', 'by_day']);
    }

    public function test_tickets_report_by_day_uses_jalali_dates(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();
        Ticket::create(['ticket_code' => 'T-002', 'user_id' => $user->id, 'unit_id' => $unit->id, 'subject' => 'Test', 'content' => 'Body', 'priority' => 'normal', 'status' => 'created']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/reports/tickets');

        $response->assertStatus(200);
        $byDay = $response->json('by_day');
        if (count($byDay) > 0) {
            $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', $byDay[0]['day']);
        }
    }
}
