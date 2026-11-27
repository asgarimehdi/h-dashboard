<?php

use App\Http\Resources\AttachmentResource;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

covers(AttachmentResource::class, TicketDetailResource::class, TicketResource::class);

uses(TestCase::class, RefreshDatabase::class);

/**
 * Issue #1953 — the attachment payload is an explicit allowlist: `file_path`
 * (private-disk layout) must never reach a client, and a column added later
 * must be added here on purpose (fail-closed, like #1802).
 */
beforeEach(function () {
    $unit = Unit::factory()->create();
    $user = User::factory()->create();

    $this->ticket = Ticket::create([
        'ticket_code' => 'T-RES-1953',
        'user_id' => $user->id,
        'unit_id' => $unit->id,
        'subject' => 'پیوست',
        'content' => 'متن',
        'priority' => 'normal',
        'status' => 'created',
    ]);

    $this->attachment = Attachment::create([
        'ticket_id' => $this->ticket->id,
        'user_id' => $user->id,
        'file_path' => 'attachments/secret.pdf',
        'file_name' => 'report.pdf',
        'file_size' => 9,
    ]);
});

it('exposes only the allowlisted keys and never file_path', function () {
    $payload = (new AttachmentResource($this->attachment))->toArray(new Request);

    expect($payload)->not->toHaveKey('file_path');
    expect(array_keys($payload))->toEqualCanonicalizing([
        'id', 'ticket_id', 'user_id', 'file_name', 'file_size',
        'activity_id', 'created_at', 'updated_at', 'url',
    ]);
    expect($payload['file_name'])->toBe('report.pdf');
    expect($payload['url'])->toBe(route('api.tickets.attachments.download', [
        'ticket' => $this->ticket->id,
        'attachment' => $this->attachment->id,
    ]));
});

it('keeps file_path out of both ticket resources that embed the attachment', function () {
    $this->ticket->load('attachments');

    $detail = (new TicketDetailResource($this->ticket))->toArray(new Request);
    $list = (new TicketResource($this->ticket))->toArray(new Request);

    expect($detail['attachments']->toArray(new Request)[0])->not->toHaveKey('file_path');
    expect($list['attachments']->toArray(new Request)[0])->not->toHaveKey('file_path');
});
