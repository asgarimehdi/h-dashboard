<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * #1729 — POST /livewire-{hash}/update is the single entry point for every
 * Livewire action; it carries a per-user (guest: per-IP) endpoint budget.
 */
#[CoversNothing]
class LivewireUpdateThrottleTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    /** Must equal RateLimiter::for('livewire-update') in AppServiceProvider. */
    private const LIMIT = 120;

    protected function setUp(): void
    {
        parent::setUp();

        // tests/TestCase.php swaps ThrottleRequests for a no-op (#705). This
        // class asserts the limiter itself, so restore the real middleware.
        $this->app->forgetInstance(ThrottleRequests::class);

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    private function postUpdate(array $payload)
    {
        // The path is /livewire-{hash}/update, derived from APP_KEY — never
        // hardcode it. Both headers are required; RequireLivewireHeaders
        // rejects the request without them.
        return $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), $payload);
    }

    private function emptyPayload(): array
    {
        return ['components' => []];
    }

    private function validPayload(): array
    {
        // Livewire::test() bypasses HTTP middleware, so use it only to mint a
        // real snapshot; the request below still goes through the route.
        $component = Livewire::test('todo.todo');

        return ['components' => [[
            'snapshot' => json_encode($component->snapshot),
            'updates' => [],
            'calls' => [],
        ]]];
    }

    public function test_update_route_carries_the_named_throttle(): void
    {
        $route = app('router')->getRoutes()->getByName('livewire.update');

        $this->assertNotNull($route, 'the custom Livewire update route is not registered');
        $this->assertContains('throttle:livewire-update', $route->gatherMiddleware());
    }

    public function test_budget_is_capped_per_user_and_normal_use_is_ok(): void
    {
        ['user' => $userA] = $this->createUserWithUnit(role: 'admin');
        $this->actingAs($userA);

        // A real round trip inside the budget still succeeds.
        $this->postUpdate($this->validPayload())->assertOk();

        // Burn the rest of this user's budget with cheap rejects; they still
        // pass the throttle middleware (the controller 404s afterwards).
        for ($i = 1; $i < self::LIMIT; $i++) {
            $this->postUpdate($this->emptyPayload())->assertNotFound();
        }

        $this->postUpdate($this->emptyPayload())
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // The counter is per user, not global.
        ['user' => $userB] = $this->createUserWithUnit(role: 'admin');
        $this->actingAs($userB);
        $this->postUpdate($this->validPayload())->assertOk();
    }

    public function test_guest_requests_are_ip_keyed_and_do_not_error(): void
    {
        // A guest (the /login page is Livewire) reaches the endpoint too.
        $this->postUpdate($this->emptyPayload())->assertNotFound();

        for ($i = 1; $i < self::LIMIT; $i++) {
            $this->postUpdate($this->emptyPayload())->assertNotFound();
        }

        $this->postUpdate($this->emptyPayload())->assertStatus(429);
    }
}
