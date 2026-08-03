<div>
    <x-modal wire:model="showModal" title="کامنت‌ها و گفتگو" separator class="max-w-2xl">
        @if ($ticket)
            <div class="space-y-4 text-right" dir="rtl">
                {{-- Ticket header --}}
                <div class="p-3 bg-base-200/60 rounded-lg">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm">{{ $ticket->ticket_code }}</span>
                        <span class="badge badge-sm">{{ $ticket->status }}</span>
                    </div>
                    <p class="text-sm mt-1">{{ $ticket->subject }}</p>
                </div>

                {{-- New comment form --}}
                <div class="flex gap-2 items-start">
                    <x-input wire:model="body" placeholder="نظر خود را بنویسید..." class="flex-1" />
                    <x-button icon="o-paper-airplane" wire:click="addComment" class="btn-primary btn-sm" spinner />
                </div>
                @error('body') <p class="text-error text-xs">{{ $message }}</p> @enderror

                {{-- Comments list --}}
                <div class="space-y-3 max-h-96 overflow-y-auto pr-1">
                    @forelse ($ticket->comments as $comment)
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
                                            <x-button icon="o-pencil" wire:click="startEdit({{ $comment->id }})" class="btn-ghost btn-xs" />
                                        @endif
                                        <x-button icon="o-trash" wire:click="deleteComment({{ $comment->id }})" wire:confirm="کامنت حذف شود؟" class="btn-ghost btn-xs text-error" />
                                    @endif
                                </div>
                            </div>

                            @if ($this->editing && $this->editCommentId === $comment->id)
                                <div class="flex gap-2 items-start mt-2">
                                    <x-input wire:model="editBody" class="flex-1" />
                                    <x-button icon="o-check" wire:click="saveEdit" class="btn-success btn-xs" spinner />
                                    <x-button icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-xs" />
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
                                    <x-button icon="o-paper-airplane" wire:click="addReply({{ $comment->id }})" class="btn-primary btn-xs" spinner />
                                    <x-button icon="o-x-mark" wire:click="cancelReply" class="btn-ghost btn-xs" />
                                </div>
                            @endif

                            {{-- Replies (children) --}}
                            @if ($comment->children->count() > 0)
                                <div class="pr-6 mt-2 space-y-2 border-r-2 border-base-200">
                                    @foreach ($comment->children as $child)
                                        <div class="bg-base-200/40 rounded-lg p-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[11px] font-bold">
                                                    {{ $child->user?->person?->f_name }} {{ $child->user?->person?->l_name }}
                                                </span>
                                                <span class="text-[10px] opacity-50 font-mono">{{ jdate($child->created_at)->format('H:i - Y/m/d') }}</span>
                                            </div>
                                            <p class="text-xs leading-5 mt-1 whitespace-pre-wrap">{!! $child->body_html !!}</p>
                                            @if ($child->user_id === auth()->id())
                                                <div class="flex gap-1 mt-1">
                                                    <x-button icon="o-pencil" wire:click="startEdit({{ $child->id }})" class="btn-ghost btn-xs" />
                                                    <x-button icon="o-trash" wire:click="deleteComment({{ $child->id }})" wire:confirm="کامنت حذف شود؟" class="btn-ghost btn-xs text-error" />
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
