<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActorLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1504 — direct unit coverage for the actor-label contract of #906.
 *
 * `ActorLabel::for()` only distinguishes the three actor states when the
 * relation feeding it was loaded by `ActorLabel::eagerLoadActor()`. The HTML
 * tests named in the issue pin the wording, but nothing proved that contract
 * itself: a user dropped from the eager load, or loaded without `n_code`, or
 * without `deleted_at`, still renders a plausible-looking label. These tests
 * assert the contract at the model level (no HTTP, no permissions).
 */
#[CoversClass(ActorLabel::class)]
class ActorLabelTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    public function test_a_null_actor_is_labelled_as_the_system(): void
    {
        $this->assertSame(ActorLabel::SYSTEM, ActorLabel::for(null));
    }

    public function test_a_soft_deleted_user_is_labelled_inactive_on_the_model_and_via_the_ticket_relation(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createActorWithName('صادق', 'بیگلر');
        $ticket = Ticket::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

        $user->delete();

        $this->assertSame(ActorLabel::INACTIVE_USER, ActorLabel::for($user));

        $loaded = Ticket::query()
            ->with(['user' => fn ($query) => ActorLabel::eagerLoadActor($query)])
            ->findOrFail($ticket->id);

        $this->assertNotNull($loaded->user);
        $this->assertSame(ActorLabel::INACTIVE_USER, ActorLabel::for($loaded->user));
    }

    public function test_an_active_user_with_a_person_renders_the_trimmed_full_name_from_the_eager_load(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createActorWithName('صادق', 'بیگلر');
        $ticket = Ticket::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

        $loaded = Ticket::query()
            ->with(['user' => fn ($query) => ActorLabel::eagerLoadActor($query)])
            ->findOrFail($ticket->id);

        $this->assertNotNull($loaded->user);
        $this->assertNotNull(
            $loaded->user->person,
            'The eager load dropped n_code from the user select, so the person relation cannot match.',
        );
        $this->assertSame('صادق بیگلر', ActorLabel::for($loaded->user));
    }

    public function test_an_active_user_without_a_person_renders_an_empty_label(): void
    {
        ['user' => $user] = $this->createActorWithName('صادق', 'بیگلر');

        // Not reachable through the FK (users.n_code is restricted), so the
        // only way to exercise the branch is to null the relation in memory.
        $user->setRelation('person', null);

        $this->assertSame('', ActorLabel::for($user));
    }

    public function test_a_blank_person_name_renders_an_empty_label(): void
    {
        ['user' => $user] = $this->createActorWithName('  ', '');

        $this->assertSame('', ActorLabel::for($user));
    }

    public function test_the_eager_load_keeps_a_soft_deleted_user_resolvable(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createActorWithName('صادق', 'بیگلر');
        $ticket = Ticket::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

        $user->delete();

        $loaded = Ticket::query()
            ->with(['user' => fn ($query) => ActorLabel::eagerLoadActor($query)])
            ->findOrFail($ticket->id);

        $this->assertNotNull($loaded->user);
        $this->assertTrue($loaded->user->trashed());
        $this->assertArrayHasKey('deleted_at', $loaded->user->getAttributes());
    }

    public function test_a_plain_eager_load_drops_the_soft_deleted_user(): void
    {
        ['user' => $user, 'unit' => $unit] = $this->createActorWithName('صادق', 'بیگلر');
        $ticket = Ticket::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

        $user->delete();

        $loaded = Ticket::query()->with('user')->findOrFail($ticket->id);

        $this->assertNull(
            $loaded->user,
            'The default soft-delete scope is expected to drop the user — without it this control stops proving anything.',
        );
    }

    /**
     * @return array{user: User, unit: Unit}
     */
    private function createActorWithName(string $fName, string $lName): array
    {
        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit();

        Person::query()
            ->where('n_code', $user->n_code)
            ->update(['f_name' => $fName, 'l_name' => $lName]);

        $user->unsetRelation('person');

        return ['user' => $user, 'unit' => $unit];
    }
}
