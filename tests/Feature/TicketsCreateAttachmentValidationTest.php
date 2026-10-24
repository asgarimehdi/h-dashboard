<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Unit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #873 — the attachment allowlist only lived in the `updated` hook.
 *
 * `updatedFiles()` validated `files.*` with `mimes:...|max:5120`, but that
 * error is a per-request artefact of the upload hook: `saveTicket()` is a
 * separate POST with a fresh error bag and never validated `files`, so it
 * stored whatever the client sent, straight to the `public` disk. Reproduced on
 * this tree before the fix — a `shell.php` landed at
 * `attachments/IRk8vLyzhgCnvuXGTTSD12Xkuigy2JOjlqMPw1kv.php` on the `public`
 * disk, the ticket was created, and the error bag was empty.
 *
 * Anyone holding `create_ticket` — the lowest role, `RoleSeeder.php:59` —
 * could therefore write a file of any type, up to Livewire's 12MB
 * temporary-upload ceiling, into `public/storage/attachments`.
 *
 * The fix validates in the method that writes, copying the pattern from
 * `⚡inbox.blade.php:676-677` (`submitAction()`).
 *
 * These cases live in their own file rather than `TicketsCreateLivewireTest.php`
 * so two branches do not edit the same fixture file for the same component.
 *
 * Note on assertion keys: `files.*` failures are keyed `files.0`, not `files`,
 * so the assertions name the concrete index. `assertHasErrors(['files'])` does
 * a literal key lookup and would not match.
 *
 * @see TicketsCreateLivewireTest for the happy-path and
 *      `updated`-hook coverage of the same component.
 */
covers(Ticket::class);

class TicketsCreateAttachmentValidationTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    protected function createTargetUnit(string $name = 'واحد پیوست'): Unit
    {
        return Unit::create([
            'name' => $name,
            'is_active' => true,
            'can_receive_tickets' => true,
        ]);
    }

    /**
     * Drive the component all the way to `saveTicket()` with one attachment,
     * faking the `public` disk so nothing touches the real storage.
     */
    protected function submitWithFile(UploadedFile $file, string $subject = 'تیکت با پیوست'): Testable
    {
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        Session::put('current_unit_id', $unit->id);

        $target = $this->createTargetUnit();

        return Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', $subject)
            ->set('content', 'محتوای تستی برای بررسی مسیر ذخیره پیوست')
            ->set('files', [$file])
            ->call('saveTicket');
    }

    /**
     * The core regression: a file whose type is outside the allowlist must be
     * rejected on the WRITE path — not merely flagged by the `updated` hook.
     */
    public function test_save_rejects_a_disallowed_file_type_and_writes_nothing(): void
    {
        $shell = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');

        $this->submitWithFile($shell)
            ->assertHasErrors(['files.0' => 'mimes']);

        // Validation runs before Ticket::create, so no ticket row exists either.
        $this->assertDatabaseMissing('tickets', ['subject' => 'تیکت با پیوست']);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * Same path, size instead of type: over the 5MB allowlist but under
     * Livewire's 12MB temporary-upload ceiling, so it does reach the component.
     */
    public function test_save_rejects_an_oversized_file_and_writes_nothing(): void
    {
        $big = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');

        $this->submitWithFile($big)
            ->assertHasErrors(['files.0' => 'max']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'تیکت با پیوست']);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * The whole point of the issue: the `updated` hook rejects the file, and
     * that rejection alone never stopped `saveTicket` from storing it. A direct
     * call with the error bag already populated must still be refused.
     */
    public function test_a_previously_flagged_file_is_not_stored_by_a_later_save_call(): void
    {
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        Session::put('current_unit_id', $unit->id);
        $target = $this->createTargetUnit();

        $shell = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');

        $component = Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'تیکت با پیوست')
            ->set('content', 'محتوای تستی برای بررسی مسیر ذخیره پیوست')
            ->set('files', [$shell])
            ->assertHasErrors(['files.0' => 'mimes']);

        // The error bag is per-request; the write must re-check regardless.
        $component->call('saveTicket')
            ->assertHasErrors(['files.0' => 'mimes']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'تیکت با پیوست']);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * The allowlist stays the one this component already documents — the fix
     * must not silently widen it. `docx`/`xlsx` stay out of scope.
     */
    public function test_save_still_rejects_docx_which_is_outside_the_ticket_allowlist(): void
    {
        $docx = UploadedFile::fake()->create('report.docx', 10, 'application/msword');

        $this->submitWithFile($docx)
            ->assertHasErrors(['files.0' => 'mimes']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'تیکت با پیوست']);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * An over-limit payload never reaches the disk.
     *
     * The count guard fires in the `updated` hook first, which empties
     * `$this->files` — so by the time `saveTicket()` runs there is nothing left
     * to reject and no error is raised on THAT request (the hook's message is
     * per-request). The security-relevant property is the one asserted here:
     * not one of the six files is written. The hook's own UX — the Persian
     * "حداکثر ۵ فایل مجاز است" message and the emptied property — is already
     * pinned by `TicketsCreateLivewireTest::test_file_validation_rejects_too_many_files`.
     */
    public function test_save_stores_no_file_when_the_payload_exceeds_five_files(): void
    {
        Storage::fake('public');

        ['user' => $user, 'unit' => $unit] = $this->createUserWithUnit(['create_ticket']);
        Session::put('current_unit_id', $unit->id);
        $target = $this->createTargetUnit();

        $files = [];
        for ($i = 0; $i < 6; $i++) {
            $files[] = UploadedFile::fake()->create("file{$i}.pdf", 10, 'application/pdf');
        }

        Livewire::actingAs($user)
            ->test('tickets.create')
            ->set('unit_id', $target->id)
            ->set('subject', 'تیکت با پیوست')
            ->set('content', 'محتوای تستی برای بررسی مسیر ذخیره پیوست')
            ->set('files', $files)
            ->assertHasErrors(['files'])
            ->assertSet('files', [])
            ->call('saveTicket');

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles('attachments'));
    }

    /**
     * A permitted file still saves — the validation must not break the
     * happy path the existing suite covers.
     */
    public function test_save_still_persists_an_allowed_file(): void
    {
        $this->submitWithFile(
            UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')
        )->assertHasNoErrors();

        $ticket = Ticket::where('subject', 'تیکت با پیوست')->firstOrFail();

        $this->assertSame(1, Attachment::where('ticket_id', $ticket->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('attachments'));
    }
}
