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

        return Ticket::query()
            ->with(['comments' => fn ($q) => $q->with('user.person')->with('children.user.person')->orderByDesc('created_at')])
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
        $this->validate(['body' => 'required|string|min:1|max:5000']);

        $ticket = $this->accessibleThreadTicket();
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
        $this->validate(['replyBody' => 'required|string|min:1|max:5000']);

        $ticket = $this->accessibleThreadTicket();
        if (! $ticket) {
            return;
        }

        // والد باید متعلق به همین تیکت باشد (ایشو #814) — وگرنه پاسخ به
        // کامنتِ تیکتِ دیگری گره می‌خورد.
        $parent = $this->findTicketComment($commentId);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'parent_id' => $parent->id,
            'body' => $this->replyBody,
            'body_html' => nl2br(e($this->replyBody)),
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
                        <div class="border border-base-300 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold">
                                        {{ mb_substr($comment->user?->person?->f_name ?? '؟', 0, 1) }}
                                    </div>
                                    <span class="text-xs font-bold">
                                        {{ $comment->user?->person?->f_name }} {{ $comment->user?->person?->l_name }}
                                        @if ($comment->is_system)
                                            <span class="badge badge-xs badge-info">سیستمی</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] opacity-50 font-mono">{{ jdate($comment->created_at)->format('H:i - Y/m/d') }}</span>
                                    @if ($comment->user_id === auth()->id())
                                        @if (!$this->editing || $this->editCommentId !== $comment->id)
                                            <x-ui.icon-button name="ویرایش کامنت" icon="o-pencil" wire:click="startEdit({{ $comment->id }})" class="btn-ghost btn-xs" />
                                        @endif
                                        <x-ui.icon-button name="حذف کامنت" icon="o-trash" wire:click="deleteComment({{ $comment->id }})" wire:confirm="کامنت حذف شود؟" class="btn-ghost btn-xs text-error" />
                                    @endif
                                </div>
                            </div>

                            @if ($this->editing && $this->editCommentId === $comment->id)
                                <div class="flex gap-2 items-start mt-2">
                                    <x-input wire:model="editBody" class="flex-1" />
                                    <x-ui.icon-button name="ذخیره کامنت" icon="o-check" wire:click="saveEdit" class="btn-success btn-xs" spinner />
                                    <x-ui.icon-button name="انصراف از ویرایش کامنت" icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-xs" />
                                </div>
                            @else
                                <p class="text-sm leading-6 whitespace-pre-wrap">{!! $comment->body_html !!}</p>
                            @endif

                            {{-- Reply button --}}
                            <div class="mt-2">
                                <x-button icon="o-chat-bubble-left" label="پاسخ" wire:click="startReply({{ $comment->id }})" class="btn-ghost btn-xs text-primary" />
                            </div>

                            {{-- Reply form --}}
                            @if ($replyToId === $comment->id)
                                <div class="flex gap-2 items-start mt-2 border-t border-base-200 pt-2">
                                    <x-input wire:model="replyBody" placeholder="پاسخ شما..." class="flex-1" />
                                    <x-ui.icon-button name="ارسال پاسخ" icon="o-paper-airplane" wire:click="addReply({{ $comment->id }})" class="btn-primary btn-xs" spinner />
                                    <x-ui.icon-button name="انصراف از ارسال پاسخ" icon="o-x-mark" wire:click="cancelReply" class="btn-ghost btn-xs" />
                                </div>
                            @endif

                            {{-- Replies (children) --}}
                            @if ($comment->children->count() > 0)
                                <div class="pr-6 mt-2 space-y-2 border-r-2 border-base-200">
                                    @foreach ($comment->children as $child)
                                        <div class="border border-base-300 rounded-lg p-3">
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center gap-2">
                                                    <div class="w-7 h-7 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold">
                                                        {{ mb_substr($child->user?->person?->f_name ?? '؟', 0, 1) }}
                                                    </div>
                                                    <span class="text-xs font-bold">
                                                        {{ $child->user?->person?->f_name }} {{ $child->user?->person?->l_name }}
                                                        @if ($child->is_system)
                                                            <span class="badge badge-xs badge-info">سیستمی</span>
                                                        @endif
                                                    </span>
                                                </div>
                                                <span class="text-[10px] opacity-50 font-mono">{{ jdate($child->created_at)->format('H:i - Y/m/d') }}</span>
                                            </div>
                                            <p class="text-sm leading-6 mt-2 whitespace-pre-wrap">{!! $child->body_html !!}</p>
                                            @if ($child->user_id === auth()->id())
                                                <div class="flex gap-1 mt-2">
                                                    <x-ui.icon-button name="ویرایش پاسخ" icon="o-pencil" wire:click="startEdit({{ $child->id }})" class="btn-ghost btn-xs" />
                                                    <x-ui.icon-button name="حذف پاسخ" icon="o-trash" wire:click="deleteComment({{ $child->id }})" wire:confirm="کامنت حذف شود؟" class="btn-ghost btn-xs text-error" />
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
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
