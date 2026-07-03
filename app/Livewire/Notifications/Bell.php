<?php

namespace App\Livewire\Notifications;

use Livewire\Component;
use App\Models\Notification;

class Bell extends Component
{
    public $notifications = [];
    public int $unreadCount = 0;
    public bool $showDropdown = false;

    public function mount(): void
    {
        $this->loadNotifications();
    }

    public function loadNotifications(): void
    {
        $this->notifications = Notification::where('user_id', auth()->id())
            ->latest()
            ->take(15)
            ->get()
            ->toArray();
        $this->unreadCount = Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->count();
    }

    public function toggleDropdown(): void
    {
        $this->showDropdown = !$this->showDropdown;
    }

    public function markAsRead($id): void
    {
        Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['is_read' => true, 'read_at' => now()]);
        $this->loadNotifications();
    }

    public function markAllAsRead(): void
    {
        Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
        $this->loadNotifications();
    }

    public function render()
    {
        return view('livewire.notifications.bell');
    }
}
