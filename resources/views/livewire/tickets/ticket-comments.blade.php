<?php
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\AccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ticket comments & threaded discussion (issue #222).
 * Used inside the ticket inbox page via a modal.
 */
return new class extends Component
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
        $this->loadTicket();
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->ticketId = null;
    }

    /**
     * #1176: the comment thread's ticket is no longer a public model prop —
     * hydration re-fetched a public prop by key WITHOUT relations, so any
     * update unrelated to comments dropped `user.person`/`children` and the
     * template lazy-loaded them per comment. The id stays public; the model
     * is the computed one request behind it. Fully qualified attribute — a
     * top-of-file import would shift this anonymous class's identity line
     * and break its line-keyed PHPStan baseline entries.
     */
    #[\Livewire\Attributes\Computed]
    public function ticket(): ?Ticket
    {
        if (! $this->ticketId) {
            return null;
        }

        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        // #1705: the top-level loop renders ROOTS only (a reply used to render
        // twice — once as a top-level card and once under its parent). The
        // thread is capped at depth 3 by both writers, so three `children`
        // levels are eager-loaded and rendered recursively; roots newest-first,
        // children oldest-first (API parity).
        return Ticket::query()
            ->with(['comments' => fn ($q) => $q
                ->whereNull('parent_id')
                ->with([
                    'user.person',
                    'children' => fn ($c) => $c->orderBy('id')->with([
                        'user.person',
                        'children' => fn ($g) => $g->orderBy('id')->with([
                            'user.person',
                            'children' => fn ($gg) => $gg->orderBy('id')->with('user.person'),
                        ]),
                    ]),
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')])
            ->whereKey($this->ticketId)
            ->where(fn ($q) => $q->whereIn('unit_id', $accessibleIds))
            ->first();
    }

    /**
     * Invalidate the computed thread so the next read reloads it. Kept as its
     * own public method (`->call('loadTicket')` in the tests plus the open
     * path) — the query itself lives in the `#[Computed] ticket()` above.
     */
    public function loadTicket(): void
    {
        unset($this->ticket);
    }

    public function addComment(): void
    {
        // #1662: the same write on the API is gated on
        // `permission:create_ticket` (routes/api.php:180-181). Livewire does
        // not re-apply route middleware on POST /livewire/update, so the
        // mutator re-checks it itself — before validation, like #892.
        $this->authorize('create_ticket');

        $this->validate(['body' => 'required|string|min:1|max:5000']);

        $ticket = $this->accessibleThreadTicket();
        if (! $ticket) {
            return;
        }

        // #1521: `body` is the single source of truth; the thread renders it
        // escaped. `body_html` is retired and no longer mass-assigned.
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'parent_id' => null,
            'body' => $this->body,
        ]);

        $this->body = '';
        $this->resetPage();
        $this->refreshComments();
    }

    public function refreshComments(): void
    {
        // Reload the ticket so the comments list reflects the latest data
        // immediately (no need to close & reopen the modal).
        // #1176: binding constraint — this method must NOT be deleted; as a
        // computed the reload IS the unset: the next read re-runs the query.
        unset($this->ticket);
    }

    public function addReply(int $commentId): void
    {
        // #1662: API parity — the reply write is gated on `create_ticket` too.
        $this->authorize('create_ticket');

        $this->validate(['replyBody' => 'required|string|min:1|max:5000']);

        $ticket = $this->accessibleThreadTicket();
        if (! $ticket) {
            return;
        }

        // والد باید متعلق به همین تیکت باشد (ایشو #814) — وگرنه پاسخ به
        // کامنتِ تیکتِ دیگری گره می‌خورد.
        $parent = $this->findTicketComment($commentId);

        // #1662: the API refuses a reply whose parent is already at depth 3
        // (TicketCommentController:135-139 → 422
        // `Maximum thread depth reached (3).`); a direct Livewire call must
        // not be able to grow the thread without bound.
        if ($this->getThreadDepth($parent) >= 3) {
            $this->addError('replyBody', 'حداکثر عمق پاسخ ۳ سطح است.');

            return;
        }

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'parent_id' => $parent->id,
            'body' => $this->replyBody,
        ]);

        $this->replyBody = '';
        $this->replyToId = null;
        $this->refreshComments();
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
        // فقط وضعیت UI؛ قاعله‌ی مالکیت/پنجره‌ی ۱۵ دقیقه‌ای در
        // TicketCommentPolicy زندگی می‌کند (ایشو #814) — اینجا کپی نمی‌شود.
        // عبور از findTicketComment لازم است: editBody state عمومیِ Livewire
        // است و سپردن بدنه‌ی کامنتِ تیکتِ دیگر به آن = نشت خواندنی.
        $comment = $this->findTicketComment($commentId);

        $this->editCommentId = $commentId;
        $this->editBody = $comment->body;
        $this->editing = true;
    }

    public function saveEdit(): void
    {
        $this->validate(['editBody' => 'required|string|min:1|max:5000']);

        if (! $this->editCommentId) {
            return;
        }

        $comment = $this->findTicketComment($this->editCommentId);
        $this->authorize('update', $comment);

        $comment->update([
            'body' => $this->editBody,
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
        $comment = $this->findTicketComment($commentId);
        $this->authorize('delete', $comment);

        $comment->delete();
        $this->refreshComments();
    }

    /**
     * Load a comment only if it belongs to the ticket whose modal is open.
     * Anything else (missing comment, no open ticket, a comment from another
     * ticket) is refused — that is the cross-ticket IDOR of issue #814; the
     * API already enforces the same `$comment->ticket_id === $ticket->id`.
     */
    private function findTicketComment(int $commentId): TicketComment
    {
        $comment = TicketComment::find($commentId);
        $threadTicket = $this->accessibleThreadTicket();

        if (! $comment || ! $threadTicket || $comment->ticket_id !== $threadTicket->id) {
            throw new AuthorizationException;
        }

        return $comment;
    }

    /**
     * Depth of a comment in its thread (root = 0), mirroring
     * Api\TicketCommentController::getThreadDepth() — the API rejects a reply
     * whose parent is already at depth 3 ("Maximum thread depth reached (3).",
     * 422), so a thread never grows past four levels.
     *
     * The API reads the chain with a recursive CTE over the raw table, which
     * does not apply the SoftDeletes scope — a soft-deleted ancestor still
     * counts there. `withTrashed()` below keeps the two paths in agreement.
     *
     * Walks at most 3 ancestors: the caller only needs the `>= 3` decision,
     * which also bounds the query count.
     */
    private function getThreadDepth(TicketComment $comment): int
    {
        $depth = 0;
        $node = $comment;

        while ($node->parent_id !== null && $depth < 3) {
            $node = TicketComment::withTrashed()->find($node->parent_id);

            if (! $node) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    /**
     * #1176: the open thread's ticket, re-derived from the public id with the
     * same fail-closed scope shape as the load — null when nothing is open or
     * the ticket has left the viewer's scope. The WRITE paths rule on this
     * current-state lookup instead of introspecting the computed model.
     */
    private function accessibleThreadTicket(): ?Ticket
    {
        if (! $this->ticketId) {
            return null;
        }

        return Ticket::query()
            ->whereKey($this->ticketId)
            ->where(fn ($q) => $q->whereIn('unit_id', app(AccessService::class)->accessibleUnitIds()))
            ->first();
    }
};
?>

<div>
    <x-modal wire:model="showModal" title="کامنت‌ها و گفتگو" separator class="max-w-2xl">
        @if ($this->ticket)
            <div class="space-y-4 text-right" dir="rtl">
                {{-- Ticket header --}}
                <div class="p-3 bg-base-200/60 rounded-lg">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm">{{ $this->ticket->ticket_code }}</span>
                        <span class="badge badge-sm">{{ $this->ticket->status }}</span>
                    </div>
                    <p class="text-sm mt-1">{{ $this->ticket->subject }}</p>
                </div>

                {{-- New comment form --}}
                <div class="flex gap-2 items-start">
                    <x-input wire:model="body" placeholder="نظر خود را بنویسید..." class="flex-1" />
                    <x-ui.icon-button name="ارسال کامنت" icon="o-paper-airplane" wire:click="addComment" class="btn-primary btn-sm" spinner />
                </div>
                @error('body') <p class="text-error text-xs">{{ $message }}</p> @enderror

                {{-- Comments list --}}
                <div class="space-y-3 max-h-96 overflow-y-auto pr-1">
                    @forelse ($this->ticket->comments as $comment)
                        @include('livewire.tickets._comment-node', [
                            'comment' => $comment,
                            'depth' => 0,
                            'replyToId' => $this->replyToId,
                            'editing' => $this->editing,
                            'editCommentId' => $this->editCommentId,
                        ])
                    @empty
                        <p class="text-center text-sm text-base-content/50 py-8">هنوز کامنتی ثبت نشده است.</p>
                    @endforelse
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-button label="بستن" wire:click="close" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>
</div>
