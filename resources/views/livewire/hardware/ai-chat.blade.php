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
            $agent = new HardwareAgent();
            $response = $agent->prompt($message);

            $this->chatHistory[] = [
                'role' => 'assistant',
                'content' => $response,
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
