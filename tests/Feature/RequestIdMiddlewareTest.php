<?php

namespace Tests\Feature;

use App\Http\Middleware\RequestId;
use App\Jobs\CleanNotificationsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(RequestId::class)]
class RequestIdMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_carry_a_generated_request_id_header(): void
    {
        $response = $this->get('/login');

        $id = $response->headers->get('X-Request-Id');

        $this->assertNotNull($id, 'the web stack must emit an X-Request-Id header');
        $this->assertTrue(Str::isUuid($id), "X-Request-Id '{$id}' is not a UUID");
    }

    /**
     * Security test (#1068): the id is always self-generated. A caller cannot
     * choose what lands in our logs, so an inbound X-Request-Id is ignored.
     */
    public function test_request_id_ignores_a_client_supplied_header(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'attacker-chosen'])->get('/login');

        $id = $response->headers->get('X-Request-Id');

        $this->assertNotSame('attacker-chosen', $id);
        $this->assertTrue(Str::isUuid($id));
    }

    public function test_each_request_gets_a_fresh_id(): void
    {
        $first = $this->get('/login')->headers->get('X-Request-Id');
        $second = $this->get('/login')->headers->get('X-Request-Id');

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second);
    }

    public function test_middleware_puts_the_id_in_the_log_context(): void
    {
        $seen = null;
        $request = Request::create('/whatever', 'GET');

        $response = (new RequestId)->handle($request, function ($req) use (&$seen) {
            $seen = Context::get('request_id');

            return response('ok');
        });

        $this->assertNotNull($seen, 'Context must hold the id for the log processor');
        $this->assertTrue(Str::isUuid($seen));
        $this->assertSame($seen, $response->headers->get('X-Request-Id'));
    }

    /**
     * The api stack has its own middleware group — prove it is enveloped too.
     * Uses `/api/login` because an unauthenticated `/api/user` is rejected by
     * an exception, whose rendered 401 is produced outside the middleware
     * pipeline (so no middleware can echo a header onto it).
     */
    public function test_api_responses_carry_the_header_too(): void
    {
        $response = $this->postJson('/api/login', [
            'n_code' => '0000000000',
            'password' => 'wrong',
        ]);

        $response->assertUnauthorized();
        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }

    /**
     * Context (not shareContext) is what carries the id through the queue:
     * `ContextServiceProvider` dehydrates it onto every job payload and
     * hydrates it in the worker. Without this, a user's HTTP error could not be
     * correlated with the job that actually wrote the failing row.
     */
    public function test_request_id_travels_on_the_queue_payload(): void
    {
        Context::add('request_id', 'corr-123');

        dispatch(new CleanNotificationsJob)->onConnection('database');

        $payload = json_decode((string) DB::table('jobs')->latest('id')->value('payload'), true);

        $this->assertArrayHasKey('illuminate:log:context', $payload);
        $this->assertSame(
            'corr-123',
            unserialize($payload['illuminate:log:context']['data']['request_id'])
        );
    }
}
