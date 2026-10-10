<?php

use App\Models\{Ticket, ActivityLog, Notification};
use App\Models\User;
use App\Services\AccessService;
use App\Jobs\ArchiveActivityLogsJob;
use App\Jobs\CleanNotificationsJob;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Support\Facades\Cache;

return new class extends Component {
    use Toast;
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public array $stats = [];
    public int $archiveDays = 30;
    public int $activityDays = 90;
    public int $notificationDays = 7;
    public bool $showHelpModal = false;

    public function mount(): void
    {
        $this->authorize('manage_users');
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $userIds = User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')->toArray();
        $cacheKey = 'tools:stats:' . md5(implode(',', $accessibleIds) . ':' . implode(',', $userIds));

        $this->stats = Cache::remember($cacheKey, 60, function () use ($accessibleIds, $userIds) {
            return [
                // Issue #929: archived tickets stay `completed`, so they are
                // excluded here by `whereNull('archived_at')`, not by status.
                'old_tickets' => Ticket::whereIn('unit_id', $accessibleIds)
                    ->where('status', 'completed')
                    ->whereNull('archived_at')
                    ->where('completed_at', '<', now()->subDays(30))
                    ->count(),
                'old_activities' => ActivityLog::whereIn('user_id', $userIds)
                    ->where('created_at', '<', now()->subDays(90))
                    ->count(),
                'old_notifications' => Notification::whereIn('user_id', $userIds)
                    ->where('created_at', '<', now()->subDays(7))
                    ->count(),
                'total_tickets' => Ticket::whereIn('unit_id', $accessibleIds)->count(),
                'total_activities' => ActivityLog::whereIn('user_id', $userIds)->count(),
                'total_notifications' => Notification::whereIn('user_id', $userIds)->count(),
            ];
        });
    }

    public function archiveTickets(): void
    {
        // Issue #892: the route gate (`role_or_permission:manage_users`) does
        // not run on `/livewire/update`, so this mutator re-checks the same
        // permission mount() uses. It must come FIRST — the org-wide update
        // below is not scoped by anything a later check could undo.
        $this->authorize('manage_users');

        $this->validate([
            'archiveDays' => 'required|integer|min:7|max:365',
        ]);
        // Issue #929: archiving stamps `archived_at` and keeps
        // `status = 'completed'` — it never writes a new status value, so
        // every status reader (label, API filter, open-ticket counts, inbox
        // guards) keeps its meaning. One-way by design (no restore UI) and
        // idempotent via `whereNull('archived_at')`.
        $count = Ticket::whereIn('unit_id', app(AccessService::class)->accessibleUnitIds())
            ->where('status', 'completed')
            ->whereNull('archived_at')
            ->where('completed_at', '<', now()->subDays($this->archiveDays))
            ->update(['archived_at' => now()]);
        $this->success("{$count} تیکت قدیمی آرشیو شد.");
        $this->invalidateStatsCache();
        $this->mount();
    }

    public function cleanActivities(): void
    {
        // Issue #892 — see archiveTickets(). A revoked holder must not be able
        // to dispatch the activity-log purge.
        $this->authorize('manage_users');

        $this->validate([
            'activityDays' => 'required|integer|min:30|max:365',
        ]);

        // Issue #950 — purge exactly what the counters above counted. The job
        // reads an empty array as "org-wide" (`$unitIds ?: allUnitIds()`, #836),
        // so an empty scope must NOT be dispatched — that is this repo's
        // fail-open trap in a new spelling: `[]` here would delete every unit's
        // logs instead of none.
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if ($accessibleIds === []) {
            $this->error('هیچ واحدی در دامنهٔ دسترسی شما نیست؛ پاک‌سازی انجام نشد.');

            return;
        }

        ArchiveActivityLogsJob::dispatch($this->activityDays, $accessibleIds);
        $this->success('پاک‌سازی لاگ‌ها در صف اجرا شد.');
        $this->invalidateStatsCache();
        $this->mount();
    }

    public function cleanNotifications(): void
    {
        // Issue #892 — see archiveTickets(). A revoked holder must not be able
        // to dispatch the notification purge.
        $this->authorize('manage_users');

        $this->validate([
            'notificationDays' => 'required|integer|min:1|max:90',
        ]);

        // Issue #950 — see cleanActivities(). Same scope, same refusal on empty.
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();

        if ($accessibleIds === []) {
            $this->error('هیچ واحدی در دامنهٔ دسترسی شما نیست؛ پاک‌سازی انجام نشد.');

            return;
        }

        CleanNotificationsJob::dispatch($this->notificationDays, $accessibleIds);
        $this->success('پاک‌سازی اعلان‌ها در صف اجرا شد.');
        $this->invalidateStatsCache();
        $this->mount();
    }

    private function invalidateStatsCache(): void
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds();
        $userIds = User::whereHas('person', fn($q) => $q->whereIn('u_id', $accessibleIds))->pluck('id')->toArray();
        $cacheKey = 'tools:stats:' . md5(implode(',', $accessibleIds) . ':' . implode(',', $userIds));
        Cache::forget($cacheKey);
    }
};
?>

<div>
    <x-header title="🔧 ابزارهای مدیریتی" separator progress-indicator>
        <x-slot:actions>
            <x-help:button section="tools" wireModel="showHelpModal" />
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

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
                <p class="text-xs text-base-content/60">تیکت‌های تکمیل شده قدیمی رو آرشیو کن (یک‌طرفه — بازگردانی ندارد؛ تیکت آرشیوشده «پایان یافته» می‌ماند)</p>
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
