<?php

namespace App\Livewire\Hardware;

use App\Ai\Agents\HardwareAgent;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;

    public string $message = '';
    public array $chatHistory = [];
    public bool $isLoading = false;

    public function send(): void
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
            $response = HardwareAgent::make()->prompt($message);

            $this->chatHistory[] = [
                'role' => 'assistant',
                'content' => (string) $response,
            ];
        } catch (\Throwable $e) {
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
};
?>

<div dir="rtl">
    <x-header title="🤖 دستیار سخت‌افزار" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <div class="flex flex-col h-[calc(100vh-12rem)]">
        {{-- Chat messages --}}
        <div class="flex-1 overflow-y-auto p-4 space-y-4" id="hw-chat-messages">
            @forelse ($chatHistory as $msg)
                <div class="chat {{ $msg['role'] === 'user' ? 'chat-end' : 'chat-start' }}">
                    <div class="chat-header text-xs opacity-60 mb-1">
                        {{ $msg['role'] === 'user' ? 'شما' : ($msg['role'] === 'error' ? 'خطا' : 'دستیار سخت‌افزار') }}
                    </div>
                    <div class="chat-bubble {{ $msg['role'] === 'user' ? 'chat-bubble-primary' : ($msg['role'] === 'error' ? 'chat-bubble-error' : '') }}">
                        {!! nl2br(e($msg['content'])) !!}
                    </div>
                </div>
            @empty
                <div class="flex items-center justify-center h-full opacity-40">
                    <div class="text-center">
                        <x-icon name="o-cpu-chip" class="w-16 h-16 mx-auto mb-4" />
                        <p>دستیار هوشمند سخت‌افزار</p>
                        <p class="text-sm mt-2">سوالات خود درباره تجهیزات، IP، مشخصات فنی و... بپرسید</p>
                        <div class="flex flex-wrap justify-center gap-2 mt-4">
                            <button wire:click="$set('message', 'لیست کامپیوترها')" class="btn btn-outline btn-xs">لیست کامپیوترها</button>
                            <button wire:click="$set('message', 'آمار کلی سخت‌افزار')" class="btn btn-outline btn-xs">آمار کلی</button>
                            <button wire:click="$set('message', 'کامپیوترهای خاموش')" class="btn btn-outline btn-xs">خاموش‌ها</button>
                        </div>
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
                        placeholder="مثلاً: کامپیوترهای آقای رضایی چیست؟"
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
            const el = document.getElementById('hw-chat-messages');
            if (el) el.scrollTop = el.scrollHeight;
        });
    });
</script>
