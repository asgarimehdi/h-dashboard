<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportController;
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

covers(ReportController::class);

class ReportsApiTest extends TestCase
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

    protected function createApiUser(): array
    {
        $unit = Unit::create(['name' => 'واحد تست']);
        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'تست', 'l_name' => 'کاربر',
            't_id' => 1, 'e_id' => 1, 's_id' => 1, 'r_id' => 1, 'u_id' => $unit->id,
        ]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    protected function authenticateAsUser(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    // --- /api/reports/units ---

    public function test_units_report_requires_auth(): void
    {
        $this->getJson('/api/reports/units')->assertStatus(401);
    }

    public function test_units_report_returns_statistics(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();
        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/units');

        $response->assertOk()
            ->assertJsonStructure([
                'total',
                'with_boundary',
                'without_boundary',
                'by_type',
            ]);
    }

    public function test_units_report_respects_organizational_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);
        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/units');

        $data = $response->json();
        $this->assertEquals(1, $data['total']);
    }

    // --- /api/reports/todos ---

    public function test_todos_report_requires_auth(): void
    {
        $this->getJson('/api/reports/todos')->assertStatus(401);
    }

    public function test_todos_report_returns_statistics(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();
        Todo::factory()->completed()->create(['unit_id' => $unit->id]);
        Todo::factory()->pending()->create(['unit_id' => $unit->id]);
        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/todos');

        $response->assertOk()
            ->assertJsonStructure([
                'completed',
                'pending',
                'overdue',
                'by_day',
                'by_unit',
            ]);

        $data = $response->json();
        $this->assertEquals(1, $data['completed']);
        $this->assertEquals(1, $data['pending']);
    }

    public function test_todos_report_counts_overdue(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();
        Todo::factory()->overdue()->create(['unit_id' => $unit->id]);
        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/todos');

        $data = $response->json();
        $this->assertGreaterThanOrEqual(1, $data['overdue']);
    }

    // --- /api/reports/tickets ---

    public function test_tickets_report_requires_auth(): void
    {
        $this->getJson('/api/reports/tickets')->assertStatus(401);
    }

    public function test_tickets_report_returns_statistics(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();

        Ticket::create([
            'ticket_code' => 'TKT-001', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست', 'content' => 'متن', 'priority' => 'urgent', 'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست ۲', 'content' => 'متن', 'priority' => 'normal', 'status' => 'completed',
        ]);

        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/tickets');

        $response->assertOk()
            ->assertJsonStructure([
                'total',
                'by_status',
                'by_priority',
                'by_day',
            ]);

        $data = $response->json();
        $this->assertEquals(2, $data['total']);
        $this->assertArrayHasKey('urgent', $data['by_priority']);
        $this->assertArrayHasKey('normal', $data['by_priority']);
    }

    public function test_tickets_report_respects_scope(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createApiUser();
        $otherUnit = Unit::create(['name' => 'واحد دیگر']);

        Ticket::create([
            'ticket_code' => 'TKT-001', 'user_id' => $user->id, 'unit_id' => $unit->id,
            'subject' => 'تست', 'content' => 'متن', 'priority' => 'normal', 'status' => 'created',
        ]);
        Ticket::create([
            'ticket_code' => 'TKT-002', 'user_id' => $user->id, 'unit_id' => $otherUnit->id,
            'subject' => 'تست ۲', 'content' => 'متن', 'priority' => 'normal', 'status' => 'created',
        ]);

        $token = $this->authenticateAsUser($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reports/tickets');

        $data = $response->json();
        $this->assertEquals(1, $data['total']);
    }
}
