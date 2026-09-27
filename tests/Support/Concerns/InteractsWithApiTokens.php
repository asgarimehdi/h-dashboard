<?php

namespace Tests\Support\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * Helper for API tests that need real Sanctum Bearer tokens.
 *
 * The `ability:` middleware requires a real token (not Sanctum::actingAs mock).
 * Use `createApiToken()` + `apiGet()`/`apiPost()` instead of `actingAs($user, 'sanctum')`.
 *
 * IMPORTANT: Call forgetGuards() before each authenticated request. The Sanctum
 * guard caches the resolved user within the app instance, which persists across
 * multiple HTTP calls in a single test method. Without resetting, the first token's
 * user is returned for every subsequent request.
 */
trait InteractsWithApiTokens
{
    /**
     * Clear the auth guard cache so the next request resolves the user from
     * the new Bearer token instead of returning the previously-cached user.
     */
    protected function forgetGuards(): void
    {
        Auth::forgetGuards();
    }

    /**
     * Create a real Sanctum token for the user and return the plain text token.
     */
    protected function createApiToken(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    /**
     * Make an authenticated GET request using a real Bearer token.
     */
    protected function apiGet(string $url, string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    /**
     * Make an authenticated POST request using a real Bearer token.
     */
    protected function apiPost(string $url, array $data, string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson($url, $data);
    }

    /**
     * Make an authenticated PUT request using a real Bearer token.
     */
    protected function apiPut(string $url, array $data, string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->putJson($url, $data);
    }

    /**
     * Make an authenticated PATCH request using a real Bearer token.
     */
    protected function apiPatch(string $url, array $data, string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->patchJson($url, $data);
    }

    /**
     * Make an authenticated DELETE request using a real Bearer token.
     */
    protected function apiDelete(string $url, string $token): TestResponse
    {
        $this->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson($url);
    }
}
