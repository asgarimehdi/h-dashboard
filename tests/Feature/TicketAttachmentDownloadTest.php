<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

/**
 * Issue #1276 — ticket attachments used to live on the `public` disk and were
 * served straight off `/storage/<file_path>` with no authentication and no
 * unit-scope check. The file is now on the private `local` disk and every read
 * goes through an authenticated controller that re-checks the ticket's unit.
 */
#[CoversNothing]
class TicketAttachmentDownloadTest extends TestCase
{
    use InteractsWithApiTokens;
    use InteractsWithTestSetup;
    use RefreshDatabase;

    private const PDF_BYTES = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seedLookupTables();
    }

    /**
     * Create a user (in its own unit) plus a ticket — in `$unit` when given,
     * otherwise in the user's unit — and one attachment file on `local`.
     *
     * @return array{user: User, unit: Unit, ticket: Ticket, attachment: Attachment, path: string}
     */
    private function ticketWithAttachment(
        string $name = 'report.pdf',
        string $contents = self::PDF_BYTES,
        ?Unit $unit = null,
    ): array {
        ['user' => $user, 'unit' => $userUnit] = $this->createUserWithUnit([
            'view_assigned_tickets', 'view_all_tickets',
        ]);
        $unit ??= $userUnit;

        $ticket = Ticket::create([
            'ticket_code' => 'T-1276-'.$name,
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت با پیوست ۱۲۷۶',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $path = "attachments/{$name}";
        Storage::disk('local')->put($path, $contents);

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'file_path' => $path,
            'file_name' => $name,
            'file_size' => strlen($contents),
        ]);

        return compact('user', 'unit', 'ticket', 'attachment', 'path');
    }

    private function webDownloadUrl(Ticket $ticket, Attachment $attachment): string
    {
        return route('tickets.attachments.download', [
            'ticket' => $ticket->id,
            'attachment' => $attachment->id,
        ]);
    }

    private function apiDownloadUrl(Ticket $ticket, Attachment $attachment): string
    {
        return route('api.tickets.attachments.download', [
            'ticket' => $ticket->id,
            'attachment' => $attachment->id,
        ]);
    }

    // ---------------------------------------------------------------------
    // Web route
    // ---------------------------------------------------------------------

    public function test_same_unit_user_downloads_the_file(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $response = $this->actingAs($user)->get($this->webDownloadUrl($ticket, $attachment));

        $response->assertOk();
        $response->assertStreamedContent(self::PDF_BYTES);
    }

    public function test_user_outside_the_unit_scope_is_forbidden(): void
    {
        Storage::fake('local');

        // The viewer belongs to a unit of its own; the ticket sits elsewhere.
        $foreignUnit = Unit::factory()->create();

        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment(
            unit: $foreignUnit,
        );

        $this->actingAs($user)
            ->get($this->webDownloadUrl($ticket, $attachment))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Storage::fake('local');
        ['ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $this->get($this->webDownloadUrl($ticket, $attachment))
            ->assertStatus(302);
    }

    public function test_attachment_belonging_to_another_ticket_is_not_found(): void
    {
        Storage::fake('local');
        ['user' => $user, 'unit' => $unit, 'ticket' => $ticket] = $this->ticketWithAttachment();

        $otherTicket = Ticket::create([
            'ticket_code' => 'T-1276-OTHER',
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'subject' => 'تیکت دیگر',
            'content' => 'متن',
            'priority' => 'normal',
            'status' => 'created',
        ]);

        $foreignAttachment = Attachment::create([
            'ticket_id' => $otherTicket->id,
            'user_id' => $user->id,
            'file_path' => 'attachments/foreign.pdf',
            'file_name' => 'foreign.pdf',
            'file_size' => 10,
        ]);

        // Same unit, same viewer — the nested binding must still 404 rather
        // than serve another ticket's attachment.
        $this->actingAs($user)
            ->get($this->webDownloadUrl($ticket, $foreignAttachment))
            ->assertNotFound();
    }

    public function test_missing_file_on_local_disk_is_not_found(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        Storage::disk('local')->delete($attachment->file_path);

        // A row without its file must 404, not 500.
        $this->actingAs($user)
            ->get($this->webDownloadUrl($ticket, $attachment))
            ->assertNotFound();
    }

    public function test_zip_is_served_as_attachment(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment(
            'archive.zip',
            'zip-bytes',
        );

        $response = $this->actingAs($user)->get($this->webDownloadUrl($ticket, $attachment));

        $response->assertOk();
        $response->assertDownload('archive.zip');
    }

    public function test_pdf_is_served_inline(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $response = $this->actingAs($user)->get($this->webDownloadUrl($ticket, $attachment));

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_response_is_private_and_not_cached(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $response = $this->actingAs($user)->get($this->webDownloadUrl($ticket, $attachment));

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ---------------------------------------------------------------------
    // API route (Sanctum token — the web route's `unit_context` would 302 the
    // app instead, so the Flutter client gets its own route)
    // ---------------------------------------------------------------------

    public function test_api_download_requires_bearer_token(): void
    {
        Storage::fake('local');
        ['ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $this->getJson($this->apiDownloadUrl($ticket, $attachment))->assertUnauthorized();
    }

    public function test_api_download_requires_tickets_read_ability(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $token = $this->createApiToken($user, ['units:read']);

        $this->apiGet($this->apiDownloadUrl($ticket, $attachment), $token)
            ->assertForbidden();
    }

    public function test_api_download_returns_the_file(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        $token = $this->createApiToken($user, ['tickets:read']);

        $response = $this->apiGet($this->apiDownloadUrl($ticket, $attachment), $token);

        $response->assertOk();
        $response->assertStreamedContent(self::PDF_BYTES);
    }

    // ---------------------------------------------------------------------
    // Read surfaces point at the authenticated route
    // ---------------------------------------------------------------------

    public function test_inbox_links_point_at_the_authenticated_download_route(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        Livewire::actingAs($user)
            ->test('tickets.inbox')
            ->call('showTicket', $ticket->id)
            ->assertSee($this->webDownloadUrl($ticket, $attachment), escape: false);
    }

    public function test_monitoring_links_point_at_the_authenticated_download_route(): void
    {
        Storage::fake('local');
        ['user' => $user, 'ticket' => $ticket, 'attachment' => $attachment] = $this->ticketWithAttachment();

        Livewire::actingAs($user)
            ->test('tickets.monitoring')
            ->call('showTicket', $ticket->id)
            ->assertSee($this->webDownloadUrl($ticket, $attachment), escape: false);
    }

    // ---------------------------------------------------------------------
    // The old public URL is gone
    // ---------------------------------------------------------------------

    public function test_legacy_storage_url_is_not_served(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        ['user' => $user, 'attachment' => $attachment] = $this->ticketWithAttachment();

        // The copy a non-migrated host still has on the public disk.
        Storage::disk('public')->put($attachment->file_path, 'public-bytes');

        // `serve => true` on the (local) disk is what registers the framework's
        // `/storage/{path}` route; #1276 removed it.
        $this->assertFalse(Route::has('storage.local'));

        $this->actingAs($user)
            ->get('/storage/'.$attachment->file_path)
            ->assertNotFound();
    }
}
