{{--
    #1705: one comment node, rendered recursively for replies.
    State is passed explicitly so the node does not depend on `$this` in an
    include; the reply/edit forms live inside the node so they also open on
    replies. Depth 3 is the hard cap: no reply button and no children block.
--}}
@props(['comment', 'depth' => 0, 'replyToId' => null, 'editing' => false, 'editCommentId' => null])

<div class="border border-base-300 rounded-lg p-3" wire:key="comment-{{ $comment->id }}">
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
                @if (!$editing || $editCommentId !== $comment->id)
                    <x-ui.icon-button name="ویرایش کامنت" icon="o-pencil" wire:click="startEdit({{ $comment->id }})" class="btn-ghost btn-xs" />
                @endif
                <x-ui.icon-button name="حذف کامنت" icon="o-trash" wire:click="deleteComment({{ $comment->id }})" wire:confirm="کامنت حذف شود؟" class="btn-ghost btn-xs text-error" />
            @endif
        </div>
    </div>

    @if ($editing && $editCommentId === $comment->id)
        <div class="flex gap-2 items-start mt-2">
            <x-input wire:model="editBody" class="flex-1" />
            <x-ui.icon-button name="ذخیره کامنت" icon="o-check" wire:click="saveEdit" class="btn-success btn-xs" spinner />
            <x-ui.icon-button name="انصراف از ویرایش کامنت" icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-xs" />
        </div>
    @else
        <p class="text-sm leading-6 whitespace-pre-wrap">{{ $comment->body }}</p>
    @endif

    @if ($depth < 3)
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
            @error('replyBody') <p class="text-error text-xs">{{ $message }}</p> @enderror
        @endif

        {{-- Replies (children) --}}
        @if ($comment->children->isNotEmpty())
            <div class="pr-6 mt-2 space-y-2 border-r-2 border-base-200">
                @foreach ($comment->children as $child)
                    @include('livewire.tickets._comment-node', [
                        'comment' => $child,
                        'depth' => $depth + 1,
                        'replyToId' => $replyToId,
                        'editing' => $editing,
                        'editCommentId' => $editCommentId,
                    ])
                @endforeach
            </div>
        @endif
    @endif
</div>
