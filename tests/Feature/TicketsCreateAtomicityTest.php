<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Person;
use App\Models\TaskActivity;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1303 — `saveTicket()` wrote seven rows in sequence with no
 * transaction. A failure after `Ticket::create` left the ticket behind, so the
 * user saw an error and a retry created a SECOND ticket; the file was stored
 * before its row, so it could also be left orphaned.
 *
 * The fix wraps every database write in `DB::transaction()`, stores the files
 * BEFORE the transaction (so no disk I/O sits inside the lock) and deletes them
 * in `catch` before re-throwing. `notifyUnit` stays untouched inside the
 * transaction (#1304 owns the cache-invalidation boundary).
 *
 * Failure is injected with a PHP exception from a model event, never with a raw
 * SQL error: `RefreshDatabase` wraps each test in a transaction, so the
 * component's transaction compiles to a SAVEPOINT, and a poisoned connection
 * would break the outer one.
 *
 * @see TicketsCreateLivewireTest for the happy path of the same component.
 * @see TicketsCreateAttachmentValidationTest for the upload allowlist.
 */
covers(Ticket::class);

class TicketsCreateAtomicityTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Fake both disks, create the creator and a target unit, and put one
     * recipient on the target so `notifyUnit()` actually has rows to roll back.
     *
     * @return array{user: User, target: Unit}
     */
    private function prepareSubmission(): array
    {
        Storage::fake('local');
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        Session::put('current_unit_id', $unit->id);

        $target = Unit::create([
            'name' => 'واحد مقصد اتمیک',
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);

        $nCode = (string) fake()->unique()->numerify('##########');
        Person::create([
            'n_code' => $nCode, 'f_name' => 'گیرنده', 'l_name' => 'تست',
            't_id' => DB::table('tahsils')->first()->id,
            'e_id' => DB::table('estekhdams')->first()->id,
            's_id' => DB::table('semats')->first()->id,
            'r_id' => DB::table('radifs')->first()->id,
            'u_id' => $target->id,
        ]);
        $recipient = User::create(['n_code' => $nCode, 'password' => Hash::make('password')]);
        $recipient->units()->attach($target->id, ['role' => 'staff', 'is_primary' => true]);

        return ['user' => $user, 'target' => $target];
    }

    private function submit(User $user, Unit $target, ?UploadedFile $file, string $subject = 'تیکت اتمیک'): Testable
    {
        $component = Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', $subject)
            ->set('content', 'محتوای تستی برای بررسی اتمیک بودن ساخت تیکت');

        if ($file) {
            $component->set('files', [$file]);
        }

        return $component->call('saveTicket');
    }

    private function assertNothingWasWritten(): void
    {
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('todos', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('task_activities', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * Failure right after `Ticket::create` — the exact reproduction from the
     * issue. The ticket row must roll back and the stored file must be cleaned.
     */
    public function test_failure_after_ticket_create_leaves_no_ticket_and_no_file(): void
    {
        ['user' => $user, 'target' => $target] = $this->prepareSubmission();
        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $thrown = false;
        Todo::creating(function () use (&$thrown): void {
            $thrown = true;
            throw new \RuntimeException('boom');
        });

        try {
            $this->submit($user, $target, $file);
        } catch (\Throwable $e) {
            // Livewire re-throws non-validation exceptions; the DB state below
            // is the contract either way.
        }

        $this->assertTrue($thrown, 'The injected failure never fired.');
        $this->assertNothingWasWritten();
    }

    /**
     * Failure after `notifyUnit()` — the notifications it inserted must roll
     * back with the ticket, not survive it.
     */
    public function test_failure_after_notify_unit_rolls_back_the_notifications(): void
    {
        ['user' => $user, 'target' => $target] = $this->prepareSubmission();

        $thrown = false;
        TaskActivity::creating(function () use (&$thrown): void {
            $thrown = true;
            throw new \RuntimeException('boom');
        });

        try {
            $this->submit($user, $target, null);
        } catch (\Throwable $e) {
        }

        $this->assertTrue($thrown, 'The injected failure never fired.');
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    /**
     * Failure after the files were stored — pins decision (a): `store()` runs
     * before the transaction and `catch` deletes what it wrote.
     */
    public function test_failure_after_store_deletes_the_stored_file(): void
    {
        ['user' => $user, 'target' => $target] = $this->prepareSubmission();
        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $thrown = false;
        Attachment::creating(function () use (&$thrown): void {
            $thrown = true;
            throw new \RuntimeException('boom');
        });

        try {
            $this->submit($user, $target, $file);
        } catch (\Throwable $e) {
        }

        $this->assertTrue($thrown, 'The injected failure never fired.');
        $this->assertNothingWasWritten();
    }

    /**
     * The acceptance criterion: after a rolled-back attempt, a retry creates
     * exactly one ticket and one of every row — not two.
     */
    public function test_a_retry_after_a_rolled_back_attempt_creates_exactly_one_ticket(): void
    {
        ['user' => $user, 'target' => $target] = $this->prepareSubmission();
        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $failOnce = true;
        Todo::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->submit($user, $target, $file);
        } catch (\Throwable $e) {
        }

        $this->assertDatabaseCount('tickets', 0);

        // A fresh component, the same upload — the temporary file was copied,
        // not moved, so it is still readable.
        $this->submit($user, $target, $file)->assertHasNoErrors();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('todos', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('task_activities', 1);
        $this->assertDatabaseCount('attachments', 1);

        $attachment = Attachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * The successful path still writes the whole chain — and the ticket points
     * at the todo it created, with its attachment on the private disk.
     */
    public function test_successful_save_writes_the_whole_chain(): void
    {
        ['user' => $user, 'target' => $target] = $this->prepareSubmission();
        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $this->submit($user, $target, $file)->assertHasNoErrors();

        $ticket = Ticket::firstOrFail();
        $todo = Todo::firstOrFail();
        $attachment = Attachment::firstOrFail();

        $this->assertSame($todo->id, $ticket->task_id);
        $this->assertSame($ticket->id, $attachment->ticket_id);
        $this->assertSame(TaskActivity::firstOrFail()->id, $attachment->activity_id);
        Storage::disk('local')->assertExists($attachment->file_path);
    }
}
