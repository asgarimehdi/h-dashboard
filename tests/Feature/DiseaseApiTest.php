<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DiseaseMapController;
use App\Models\Disease;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_unauthenticated_disease_endpoints_return_401(): void
    {
        // The stats/map routes land in Tasks 2 and 4; each task extends this
        // list when its route exists, so the suite never pins a 404 as a 401.
        $this->getJson('/api/gis/diseases')->assertUnauthorized();
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
}
