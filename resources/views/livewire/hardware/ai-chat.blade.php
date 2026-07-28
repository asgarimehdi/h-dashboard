<?php

use App\Ai\Agents\HardwareAgent;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;

    public string $message = '';
    public array $chatHistory = [];
    public bool $isLoading = false;
    public string $sessionId = 'hw-chat-default';

    public function mount(): void
    {
        $this->loadHistory();
    }

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
            $agent = new HardwareAgent();
            $response = $agent->prompt($message);

            // Strip thinking blocks from response
            $response = $this->stripThinking($response);

            $this->chatHistory[] = [
                'role' => 'assistant',
                'content' => $response,
            ];

            // Save to session storage
            $this->saveHistory();

            // Check if AI returned a filter action
            $this->handleAIAction($response);
        } catch (\Throwable $e) {
            $this->chatHistory[] = [
                'role' => 'error',
                'content' => $e->getMessage(),
            ];
        }

        $this->isLoading = false;
        $this->dispatch('scrollToBottom');
    }

    public function clear(): void
    {
        $this->chatHistory = [];
        session()->forget("hardware_chat_history_{$this->sessionId}");
        $this->success('گفتگو پاک شد', position: 'toast-bottom');
    }

    public function saveHistory(): void
    {
        session(["hardware_chat_history_{$this->sessionId}" => $this->chatHistory]);
    }

    public function loadHistory(): void
    {
        $this->chatHistory = session("hardware_chat_history_{$this->sessionId}", []);
    }

    private function stripThinking(string $text): string
    {
        // Remove <thinking>...</thinking> blocks
        $text = preg_replace('/<thinking>.*?<\/thinking>/is', '', $text);
        // Also handle 思考...思考 (Chinese think tags) and  thinking prefix
        $text = preg_replace('/\x{601d}\x{8003}.*?\x{601d}\x{8003}/u', '', $text);
        return trim($text);
    }

    private function handleAIAction(string $response): void
    {
        // Parse JSON action from AI response (if any)
        if (preg_match('/\{"action":\s*"filter_table",\s*"filters":\s*(\{.*?\})\s*\}/', $response, $matches)) {
            $filters = json_decode($matches[1] ?? '{}', true);
            if ($filters) {
                $this->dispatch('apply-hardware-filters', filters: $filters);
            }
        }
    }

    public function renderMarkdown(string $text): string
    {
        // Convert **bold** to <strong>
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        // Convert *italic* to <em>
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Simple table conversion
        $lines = explode("\n", $text);
        $inTable = false;
        $processedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\|.+\|$/', $line)) {
                if (!$inTable) {
                    $processedLines[] = '<div class="overflow-x-auto"><table class="table table-sm table-bordered">';
                    $inTable = true;
                }
                if (str_contains($line, '---')) continue;

                $cells = array_map('trim', explode('|', $line));
                array_shift($cells);
                array_pop($cells);
                $processedLines[] = '<tr>' . implode('', array_map(fn($c) => "<td>{$c}</td>", $cells)) . '</tr>';
            } else {
                if ($inTable) {
                    $processedLines[] = '</table></div>';
                    $inTable = false;
                }
                $processedLines[] = $line;
            }
        }
        if ($inTable) $processedLines[] = '</table></div>';

        $text = implode("\n", $processedLines);
        // Convert inline code `code`
        $text = preg_replace('/`(.+?)`/', '<code class="bg-base-200 px-1 rounded text-xs">$1</code>', $text);
        return nl2br($text);
    }
}; ?>

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
                    <div class="chat-bubble {{ $msg['role'] === 'user' ? 'chat-bubble-primary' : ($msg['role'] === 'error' ? 'chat-bubble-error' : '') }} prose prose-sm max-w-none">
                        {!! $this->renderMarkdown($msg['content']) !!}
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
                            <button wire:click="$set('message', 'فیلتر روی لپ‌تاپ‌ها و رم بالای ۱۶')" class="btn btn-outline btn-xs">فیلتر لپ‌تاپ ۱۶GB+</button>
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

    // Listen for filter actions from AI
    window.Livewire.on('apply-hardware-filters', (e) => {
        if (e.detail && e.detail.filters) {
            window.Livewire.dispatch('apply-hardware-filters', e.detail.filters);
        }
    });
</script>

