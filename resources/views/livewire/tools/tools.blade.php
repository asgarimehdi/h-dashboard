<?php

use App\Models\{Ticket, ActivityLog, Notification};
use App\Services\AccessService;
use Livewire\Component;
use Mary\Traits\Toast;

return new class extends Component {
    use Toast;

    public array $stats = [];
    public int $archiveDays = 30;
    public int $activityDays = 90;
    public int $notificationDays = 7;

    public function mount(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $this->stats = [
            'old_tickets' => Ticket::whereIn('unit_id', $accessibleIds)
                ->where('status', 'completed')
                ->where('completed_at', '<', now()->subDays(30))
                ->count(),
            'old_activities' => ActivityLog::where('created_at', '<', now()->subDays(90))->count(),
            'old_notifications' => Notification::where('created_at', '<', now()->subDays(7))->count(),
            'total_tickets' => Ticket::whereIn('unit_id', $accessibleIds)->count(),
            'total_activities' => ActivityLog::count(),
            'total_notifications' => Notification::count(),
        ];
    }

    public function archiveTickets(): void
    {
        $this->validate([
            'archiveDays' => 'required|integer|min:7|max:365',
        ]);
        $count = Ticket::whereIn('unit_id', app(AccessService::class)->accessibleUnitIds())
            ->where('status', 'completed')
            ->where('completed_at', '<', now()->subDays($this->archiveDays))
            ->update(['status' => 'archived']);
        $this->success("{$count} تیکت قدیمی آرشیو شد.");
        $this->mount();
    }

    public function cleanActivities(): void
    {
        $this->validate([
            'activityDays' => 'required|integer|min:30|max:365',
        ]);
        $count = ActivityLog::where('created_at', '<', now()->subDays($this->activityDays))->delete();
        $this->success("{$count} لاگ قدیمی پاک شد.");
        $this->mount();
    }

    public function cleanNotifications(): void
    {
        $this->validate([
            'notificationDays' => 'required|integer|min:1|max:90',
        ]);
        $count = Notification::where('created_at', '<', now()->subDays($this->notificationDays))->delete();
        $this->success("{$count} اعلان قدیمی پاک شد.");
        $this->mount();
    }
};
?>

<div>
    <x-header title="🔧 ابزارهای مدیریتی" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    {{-- آمار --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <x-stat title="تیکت‌های تکمیل شده قدیمی (۳۰+ روز)" value="{{ $stats['old_tickets'] }}" icon="o-archive-box" color="text-warning" />
        <x-stat title="لاگ‌های قدیمی (۹۰+ روز)" value="{{ $stats['old_activities'] }}" icon="o-document-text" color="text-info" />
        <x-stat title="اعلان‌های قدیمی (۷+ روز)" value="{{ $stats['old_notifications'] }}" icon="o-bell" color="text-primary" />
    </div>

    {{-- ابزارها --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        {{-- آرشیو تیکت‌ها --}}
        <x-card shadow>
            <div class="card-body">
                <h2 class="card-title text-sm">📦 آرشیو تیکت‌ها</h2>
                <p class="text-xs text-base-content/60">تیکت‌های تکمیل شده قدیمی رو آرشیو کن</p>
                <x-form wire:submit.prevent="archiveTickets" class="space-y-2">
                    <x-input type="number" wire:model="archiveDays" min="7" max="365" placeholder="تعداد روز" />
                    <x-button type="submit" label="آرشیو کن" class="btn-warning btn-sm w-full" spinner />
                </x-form>
            </div>
        </x-card>

        {{-- پاک‌سازی لاگ‌ها --}}
        <x-card shadow>
            <div class="card-body">
                <h2 class="card-title text-sm">🗑️ پاک‌سازی لاگ‌ها</h2>
                <p class="text-xs text-base-content/60">لاگ‌های قدیمی فعالیت رو پاک کن</p>
                <x-form wire:submit.prevent="cleanActivities" class="space-y-2">
                    <x-input type="number" wire:model="activityDays" min="30" max="365" placeholder="تعداد روز" />
                    <x-button type="submit" label="پاک کن" class="btn-info btn-sm w-full" spinner />
                </x-form>
            </div>
        </x-card>

        {{-- پاک‌سازی اعلان‌ها --}}
        <x-card shadow>
            <div class="card-body">
                <h2 class="card-title text-sm">🔔 پاک‌سازی اعلان‌ها</h2>
                <p class="text-xs text-base-content/60">اعلان‌های قدیمی رو پاک کن</p>
                <x-form wire:submit.prevent="cleanNotifications" class="space-y-2">
                    <x-input type="number" wire:model="notificationDays" min="1" max="90" placeholder="تعداد روز" />
                    <x-button type="submit" label="پاک کن" class="btn-primary btn-sm w-full" spinner />
                </x-form>
            </div>
        </x-card>
    </div>

    <div class="mt-6 text-center">
        <a href="/" class="btn btn-ghost btn-sm">← بازگشت به داشبورد</a>
    </div>
</div>
