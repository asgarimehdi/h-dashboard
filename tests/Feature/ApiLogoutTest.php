<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

#[CoversNothing]
class ApiLogoutTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create a user and a real Bearer token with the user's own abilities,
     * returning the plain-text token and the stored PersonalAccessToken id.
     *
     * @return array{token: string, id: int}
     */
    private function userWithToken(): array
    {
        ['user' => $user] = $this->createUserWithUnit();
        $new = $user->createToken('flutter-app', $user->getApiTokenAbilities());

        return ['token' => $new->plainTextToken, 'id' => $new->accessToken->id];
    }

    public function test_logout_revokes_the_current_token(): void
    {
        ['token' => $token, 'id' => $tokenId] = $this->userWithToken();

        $this->apiPost('/api/logout', [], $token)->assertNoContent();

        // Assert on the DATABASE, not on a status code alone — a swallowed
        // failure must not pass (the #936 lesson).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // The now-revoked token is rejected on the next request.
        $this->apiGet('/api/user', $token)->assertUnauthorized();
    }

    public function test_logout_keeps_other_device_tokens(): void
    {
        ['user' => $user] = $this->createUserWithUnit();
        $first = $user->createToken('flutter-app', $user->getApiTokenAbilities());
        $second = $user->createToken('flutter-app', $user->getApiTokenAbilities());

        $this->apiPost('/api/logout', [], $first->plainTextToken)->assertNoContent();

        // Only the current token dies — NOT `tokens()->delete()`.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $second->accessToken->id]);

        $this->apiGet('/api/user', $second->plainTextToken)->assertOk();
    }

    public function test_logout_without_token_returns_401(): void
    {
        $this->forgetGuards();

        $this->postJson('/api/logout')->assertUnauthorized();
    }

    /**
     * With `statefulApi()` enabled, a browser/session request reaches the
     * Sanctum guard as a `TransientToken`, which has no `delete()` method.
     * The handler must be conditional or this is a 500.
     */
    public function test_logout_via_session_cookie_does_not_500(): void
    {
        ['user' => $user] = $this->createUserWithUnit();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
    }

    public function test_logout_does_not_revoke_another_users_token(): void
    {
        ['token' => $actorToken] = $this->userWithToken();
        ['user' => $other] = $this->createUserWithUnit();
        $otherToken = $other->createToken('flutter-app', $other->getApiTokenAbilities());

        $this->apiPost('/api/logout', [], $actorToken)->assertNoContent();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->apiGet('/api/user', $otherToken->plainTextToken)->assertOk();
    }

    public function test_logout_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/logout', 'POST')
        );

        $this->assertNotNull($route);

        $middleware = collect($route->gatherMiddleware())->map(fn ($m) => (string) $m);
        $this->assertTrue(
            $middleware->contains(fn ($m) => str_contains($m, 'throttle:api-user')),
            'POST /api/logout must sit inside the auth:sanctum + throttle:api-user group'
        );
        $this->assertTrue(
            $middleware->contains(fn ($m) => str_contains($m, 'auth:sanctum')),
            'POST /api/logout must require auth:sanctum'
        );
    }
}
