<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DiseaseMapController;
use App\Models\Disease;
use App\Models\DiseaseCase;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1245 — the three `GET /api/gis/disease*` endpoints (spec §6).
 *
 * Every request carries a real Bearer token (`InteractsWithApiTokens`) so the
 * `ability:gis:read` middleware is actually exercised. The aggregate payloads
 * are PII-free by construction: counts, rates and organizational names only.
 */
covers(DiseaseMapController::class);

class DiseaseApiTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Session::flush();
        Cache::flush();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * A real Bearer token minted the way the Flutter app gets one.
     *
     * @param  array<int, string>  $permissions
     * @param  array<int, string>|null  $abilities  null → the user's own abilities
     */
    private function tokenFor(array $permissions = ['map'], ?array $abilities = null): string
    {
        ['user' => $user] = $this->createUserWithUnit($permissions);

        return $this->createApiToken($user, $abilities ?? ['gis:read']);
    }

    private function tokenForUnit(Unit $unit, array $permissions = ['map']): string
    {
        $user = User::factory()->create();
        $user->person()->update(['u_id' => $unit->id]);
        $user->givePermissionTo($permissions);

        return $this->createApiToken($user, ['gis:read']);
    }

    private function caseOn(string $diagnosedAt, Unit $subject, Disease $disease, ?Unit $recorder = null): DiseaseCase
    {
        return DiseaseCase::factory()->create([
            'disease_id' => $disease->id,
            'unit_id' => ($recorder ?? $subject)->id,
            'subject_unit_id' => $subject->id,
            'diagnosed_at' => $diagnosedAt,
        ]);
    }

    public function test_unauthenticated_disease_endpoints_return_401(): void
    {
        // The map route lands in Task 4; that task extends this list when its
        // route exists, so the suite never pins a 404 as a 401.
        $this->getJson('/api/gis/diseases')->assertUnauthorized();
        $this->getJson('/api/gis/disease-stats')->assertUnauthorized();
    }

    public function test_diseases_returns_active_only_sorted(): void
    {
        $third = Disease::factory()->create(['sort' => 2, 'is_active' => true]);
        $inactive = Disease::factory()->create(['sort' => 1, 'is_active' => false]);
        $first = Disease::factory()->create(['sort' => 0, 'is_active' => true]);
        $second = Disease::factory()->create(['sort' => 1, 'is_active' => true]);

        $response = $this->apiGet('/api/gis/diseases', $this->tokenFor());

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($payload, 'id')
        );
        $this->assertNotContains($inactive->id, array_column($payload, 'id'));
        $this->assertSame(['id', 'name_fa', 'slug'], array_keys($payload[0]));
        $this->assertSame($first->name_fa, $payload[0]['name_fa']);
        $this->assertSame($first->slug, $payload[0]['slug']);
    }

    public function test_diseases_requires_gis_ability(): void
    {
        // A `map` holder whose token was minted without `gis:read` is stopped
        // by the ability middleware, not by the permission gate.
        $token = $this->tokenFor(['map'], ['units:read']);

        $this->apiGet('/api/gis/diseases', $token)->assertForbidden();
    }

    public function test_diseases_requires_map_permission(): void
    {
        // A token carrying `gis:read` for a user with neither `map` nor `bw`
        // is stopped by the route gate.
        $token = $this->tokenFor([], ['gis:read']);

        $this->apiGet('/api/gis/diseases', $token)->assertForbidden();
    }

    public function test_disease_stats_requires_a_disease(): void
    {
        $this->apiGet('/api/gis/disease-stats', $this->tokenFor())
            ->assertStatus(422)
            ->assertJsonValidationErrors('disease');
    }

    public function test_disease_stats_rejects_an_unknown_disease(): void
    {
        $this->apiGet('/api/gis/disease-stats?disease=999999', $this->tokenFor())
            ->assertStatus(422)
            ->assertJsonValidationErrors('disease');
    }

    public function test_disease_stats_accepts_an_inactive_disease(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $inactive = Disease::factory()->create(['is_active' => false]);
        $this->caseOn('2026-04-01', $unit, $inactive);

        $response = $this->apiGet(
            "/api/gis/disease-stats?disease={$inactive->id}&from=1405/01/01&to=1405/12/29",
            $this->tokenForUnit($unit)
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('cases'));
    }

    public function test_disease_stats_rejects_an_impossible_jalali_date(): void
    {
        $disease = Disease::factory()->create();

        $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/13/01",
            $this->tokenFor()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_disease_stats_rejects_an_inverted_range(): void
    {
        $disease = Disease::factory()->create();

        $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/06/01&to=1405/05/01",
            $this->tokenFor()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_disease_stats_defaults_to_the_current_jalali_year(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($unit);
        $disease = Disease::factory()->create();

        // 1404/12/29 — the day before the current Persian year.
        $this->caseOn('2026-03-20', $unit, $disease);
        // 1405/01/01 and 1405/12/29 — first and last day of the year.
        $this->caseOn('2026-03-21', $unit, $disease);
        $this->caseOn('2027-03-20', $unit, $disease);
        // 1406/01/01 — the day after.
        $this->caseOn('2027-03-21', $unit, $disease);

        $response = $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token);

        $response->assertOk();
        $this->assertSame(2, $response->json('cases'));
    }

    public function test_disease_stats_filters_by_the_requested_range(): void
    {
        ['unit' => $unit] = $this->createUserWithUnit(['map']);
        $token = $this->tokenForUnit($unit);
        $disease = Disease::factory()->create();

        // 1405/01/10, 1405/01/15 and 1405/01/20.
        $this->caseOn('2026-03-30', $unit, $disease);
        $this->caseOn('2026-04-04', $unit, $disease);
        $this->caseOn('2026-04-09', $unit, $disease);

        $response = $this->apiGet(
            "/api/gis/disease-stats?disease={$disease->id}&from=1405/01/11&to=1405/01/19",
            $token
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('cases'));
    }

    public function test_disease_stats_empty_scope_is_zero_and_null(): void
    {
        $user = User::factory()->create();
        $user->person()->update(['u_id' => null]);
        $user->givePermissionTo('map');
        $token = $this->createApiToken($user, ['gis:read']);
        $disease = Disease::factory()->create();

        $response = $this->apiGet("/api/gis/disease-stats?disease={$disease->id}", $token);

        $response->assertOk();
        $this->assertSame(
            ['cases' => 0, 'population' => null, 'rate' => null],
            $response->json()
        );
    }
}
