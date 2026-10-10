<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(Ticket::class);

/**
 * Issue #954 — `Ticket::PRIORITIES` is the single canonical ticket-priority
 * vocabulary. The nightly maintenance writer used to store `medium` — a value
 * the DB CHECK accepts but every aggregation and every validation rule
 * rejects — so this class pins the constant AND the fact that the API's
 * create/filter rules agree with it row-for-row.
 */
class TicketPriorityVocabularyTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    public function test_priorities_constant_is_the_canonical_vocabulary(): void
    {
        $this->assertSame(['low', 'normal', 'urgent'], Ticket::PRIORITIES);
    }

    public function test_api_store_accepts_every_canonical_priority(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        $token = $user->createToken('test-token', ['tickets:write'])->plainTextToken;

        foreach (Ticket::PRIORITIES as $priority) {
            $this->apiPost('/api/tickets', $this->payload($priority, $unit), $token)
                ->assertCreated();
        }
    }

    public function test_api_store_refuses_off_enum_priorities(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        $token = $user->createToken('test-token', ['tickets:write'])->plainTextToken;

        // `medium` and `high` pass the DB CHECK constraint (the migration on
        // purpose allows five) but the app vocabulary is three — a client
        // must not be able to create rows no report can see (#954).
        foreach (['medium', 'high'] as $priority) {
            $this->apiPost('/api/tickets', $this->payload($priority, $unit), $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['priority']);
        }
    }

    public function test_api_index_filter_rejects_off_enum_priority(): void
    {
        ['user' => $user] = $this->createUserWithUnit(['view_all_tickets']);
        $token = $user->createToken('test-token', ['tickets:read'])->plainTextToken;

        $this->apiGet('/api/tickets?priority=medium', $token)->assertStatus(422);
        $this->apiGet('/api/tickets?priority=high', $token)->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $priority, Unit $unit): array
    {
        return [
            'subject' => "Priority vocabulary check {$priority}",
            'content' => 'Body',
            'priority' => $priority,
            'unit_id' => $unit->id,
        ];
    }

    private function apiGet(string $url, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    private function apiPost(string $url, array $data, string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson($url, $data);
    }
}
