<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AiAgentTest extends TestCase
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

        $nCode = (string) rand(1000000000, 9999999999);
        Person::create(['n_code' => $nCode, 'f_name' => 'T', 'l_name' => 'U', 't_id' => $tId, 'e_id' => $eId, 's_id' => $sId, 'r_id' => $rId, 'u_id' => 1]);
        $user = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $unit = Unit::create(['name' => 'Test Unit']);
        $user->units()->attach($unit->id, ['role' => 'staff', 'is_primary' => true]);
        Session::put('current_unit_id', $unit->id);

        return ['user' => $user, 'unit' => $unit];
    }

    public function test_ai_hardware_endpoint_returns_error_when_api_key_missing(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        // Ensure no API key is configured
        config(['ai.providers.openai.key' => null]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/ai/hardware', [
            'message' => 'Show me all hardware',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_ai_chat_endpoint_returns_error_when_api_key_missing(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        // Ensure no API key is configured
        config(['ai.providers.openai.key' => null]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/ai/chat', [
            'message' => 'Hello',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'error',
            ]);
    }
}