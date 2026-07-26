<?php

use App\Services\AiService;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;

    public string $message = '';

    public array $chatHistory = [];

    public bool $isLoading = false;

    public function send(AiService $ai): void
    {
        $message = trim($this->message);

        if ($message === '') {
            return;
        }

        $this->chatHistory[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $this->message = '';
        $this->isLoading = true;

        try {
            $response = $ai->chat($message);

            $this->chatHistory[] = [
                'role' => 'assistant',
                'content' => $response,
            ];
        } catch (Throwable $e) {
            $this->chatHistory[] = [
                'role' => 'error',
                'content' => $e->getMessage(),
            ];
        }

        $this->isLoading = false;
    }

    public function clear(): void
    {
        $this->chatHistory = [];
        $this->success('گفتگو پاک شد', position: 'toast-bottom');
    }
}; ?>

<div dir="rtl">
    <x-header title="گفتگوی هوش مصنوعی" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="flex flex-col h-[calc(100vh-12rem)]">
        {{-- Chat messages --}}
        <div class="flex-1 overflow-y-auto p-4 space-y-4" id="chat-messages">
            @forelse ($chatHistory as $msg)
                <div class="chat {{ $msg['role'] === 'user' ? 'chat-end' : 'chat-start' }}">
                    <div class="chat-header text-xs opacity-60 mb-1">
                        {{ $msg['role'] === 'user' ? 'شما' : ($msg['role'] === 'error' ? 'خطا' : 'هوش مصنوعی') }}
                    </div>
                    <div class="chat-bubble {{ $msg['role'] === 'user' ? 'chat-bubble-primary' : ($msg['role'] === 'error' ? 'chat-bubble-error' : '') }}">
                        {!! nl2br(e($msg['content'])) !!}
                    </div>
                </div>
            @empty
                <div class="flex items-center justify-center h-full opacity-40">
                    <div class="text-center">
                        <x-icon name="o-chat-bubble-left" class="w-16 h-16 mx-auto mb-4" />
                        <p>پیامی ارسال نشده است</p>
                    </div>
                </div>
            @endforelse

            @if ($isLoading)
                <div class="chat chat-start">
                    <div class="chat-bubble">
                        <span class="loading loading-dots loading-sm"></span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Input area --}}
        <div class="border-t p-4 bg-base-100">
            <div class="flex gap-2 items-end">
                <div class="flex-1">
                    <textarea
                        class="textarea textarea-bordered w-full resize-none"
                        placeholder="پیام خود را بنویسید..."
                        wire:model.live="message"
                        wire:keydown.enter.prevent="send"
                        rows="2"
                        @if($isLoading) disabled @endif
                    ></textarea>
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex gap-1">
                        <x-button icon="o-paper-airplane" wire:click="send" spinner :disabled="$isLoading" class="btn-primary btn-sm" />
                        <x-button icon="o-trash" wire:click="clear" spinner class="btn-ghost btn-sm" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('scrollToBottom', () => {
            const el = document.getElementById('chat-messages');
            if (el) el.scrollTop = el.scrollHeight;
        });
    });
</script>
