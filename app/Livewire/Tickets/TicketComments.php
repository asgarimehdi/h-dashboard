<?php

namespace App\Livewire\Tickets;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\AccessService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ticket comments & threaded discussion (issue #222).
 * Used inside the ticket inbox page via a modal.
 */
class TicketComments extends Component
{
    use WithPagination;

    public ?int $ticketId = null;
    public string $body = '';
    public ?int $replyToId = null;
    public string $replyBody = '';
    public bool $showModal = false;
    public bool $editing = false;
    public ?int $editCommentId = null;
    public string $editBody = '';

    protected $listeners = ['openComments' => 'openForTicket'];

    public function openForTicket(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->body = '';
        $this->replyToId = null;
        $this->replyBody = '';
        $this->editing = false;
        $this->editCommentId = null;
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->ticketId = null;
    }

    public function getTicketProperty(): ?Ticket
    {
        if (! $this->ticketId) {
            return null;
        }
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        return Ticket::where('id', $this->ticketId)
            ->whereIn('unit_id', $accessibleIds)
            ->with(['comments' => fn ($q) => $q->with('user.person')->orderByDesc('created_at')])
            ->first();
    }

    public function addComment(): void
    {
        $this->validate(['body' => 'required|string|min:1|max:5000']);

        $ticket = $this->ticket;
        if (! $ticket) {
            return;
        }

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'parent_id' => null,
            'body' => $this->body,
            'body_html' => nl2br(e($this->body)),
        ]);

        $this->body = '';
        $this->resetPage();
    }

    public function addReply(int $commentId): void
    {
        $this->validate(['replyBody' => 'required|string|min:1|max:5000']);

        $ticket = $this->ticket;
        if (! $ticket) {
            return;
        }

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'parent_id' => $commentId,
            'body' => $this->replyBody,
            'body_html' => nl2br(e($this->replyBody)),
        ]);

        $this->replyBody = '';
        $this->replyToId = null;
    }

    public function startReply(int $commentId): void
    {
        $this->replyToId = $commentId;
        $this->replyBody = '';
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
        $this->replyBody = '';
    }

    public function startEdit(int $commentId): void
    {
        $comment = TicketComment::find($commentId);
        if (! $comment || $comment->user_id !== auth()->id()) {
            return;
        }
        $this->editCommentId = $commentId;
        $this->editBody = $comment->body;
        $this->editing = true;
    }

    public function saveEdit(): void
    {
        $this->validate(['editBody' => 'required|string|min:1|max:5000']);

        $comment = TicketComment::find($this->editCommentId);
        if (! $comment || $comment->user_id !== auth()->id()) {
            return;
        }
        if ($comment->created_at->diffInMinutes(now()) > 15) {
            session()->flash('comment_error', 'فقط تا ۱۵ دقیقه بعد از ثبت می‌توانید ویرایش کنید.');
            return;
        }

        $comment->update([
            'body' => $this->editBody,
            'body_html' => nl2br(e($this->editBody)),
        ]);

        $this->editing = false;
        $this->editCommentId = null;
        $this->editBody = '';
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->editCommentId = null;
        $this->editBody = '';
    }

    public function deleteComment(int $commentId): void
    {
        $comment = TicketComment::find($commentId);
        if (! $comment) {
            return;
        }
        // author or admin
        if ($comment->user_id === auth()->id() || auth()->user()->hasRole('admin') || auth()->user()->can('manage_tickets')) {
            $comment->delete();
        }
    }

    public function render()
    {
        return view('livewire.tickets.ticket-comments', [
            'ticket' => $this->ticket,
        ]);
    }
}
