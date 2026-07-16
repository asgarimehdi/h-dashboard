<?php

use App\Models\ActivityLog;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Morilog\Jalali\Jalalian;

return new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all';
    public ?int $userId = null;
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public bool $showModal = false;
    public ?ActivityLog $selectedLog = null;
    public array $typeStats = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }

        if (in_array($property, ['search', 'typeFilter', 'userId', 'dateFrom', 'dateTo'])) {
            $this->loadData();
        }
    }

    public function loadData(): void
    {
        $this->typeStats = $this->getTypeStats();
    }

    private function parseJalaliDate(?string $date, bool $endOfDay = false): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $date)->toCarbon();

            return $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function logs()
    {
        $query = ActivityLog::with("user");

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where("description", "like", "%" . $this->search . "%")
                    ->orWhere("type", "like", "%" . $this->search . "%");
            });
        }

        if ($this->typeFilter !== "all") {
            $query->where("type", $this->typeFilter);
        }

        if ($this->userId) {
            $query->where("user_id", $this->userId);
        }

        if ($from = $this->parseJalaliDate($this->dateFrom)) {
            $query->where("created_at", ">=", $from);
        }

        if ($to = $this->parseJalaliDate($this->dateTo, endOfDay: true)) {
            $query->where("created_at", "<=", $to);
        }

        return $query->latest()->paginate(20);
    }

    public function showDetail($id): void
    {
        $this->selectedLog = ActivityLog::with('user')->findOrFail($id);
        $this->showModal = true;
    }

    public function closeDetail(): void
    {
        $this->showModal = false;
        $this->selectedLog = null;
    }

    public function getTypeStats(): array
    {
        return ActivityLog::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }
}; ?>
<div>
    <x-header title="گزارش فعالیت سیستم" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector />
        </x-slot:actions>
    </x-header>

    {{-- آمار انواع --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
        @php
            $typeColors = [
                'created' => 'text-success',
                'updated' => 'text-info',
                'deleted' => 'text-error',
                'login' => 'text-primary',
                'logout' => 'text-warning',
            ];
            $typeLabels = [
                'created' => 'ایجاد',
                'updated' => 'ویرایش',
                'deleted' => 'حذف',
                'login' => 'ورود',
                'logout' => 'خروج',
            ];
            $typeIcons = [
                'created' => 'o-plus-circle',
                'updated' => 'o-pencil-square',
                'deleted' => 'o-trash',
                'login' => 'o-arrow-right-on-rectangle',
                'logout' => 'o-arrow-left-on-rectangle',
            ];
        @endphp
        @foreach(['created', 'updated', 'deleted', 'login', 'logout'] as $type)
        <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
            <div class="stat-title text-xs">{{ $typeLabels[$type] ?? $type }}</div>
            <div class="stat-value text-lg {{ $typeColors[$type] ?? '' }}">{{ $typeStats[$type] ?? 0 }}</div>
        </div>
        @endforeach
    </div>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4 flex-wrap">
            <div class="flex-1 min-w-[200px]">
                <x-input
                    placeholder="جستجوی فعالیت..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full" />
            </div>
            <div class="flex gap-1 flex-wrap">
                @foreach(['all' => 'همه', 'created' => 'ایجاد', 'updated' => 'ویرایش', 'deleted' => 'حذف', 'login' => 'ورود', 'logout' => 'خروج'] as $key => $label)
                <x-button
                    label="{{ $label }}"
                    wire:click="$set('typeFilter', '{{ $key }}')"
                    class="btn-xs {{ $this->typeFilter === $key ? 'btn-primary' : 'btn-outline' }}" />
                @endforeach
            </div>
            <div class="flex gap-2" wire:ignore>
                <input data-jdp id="activity_log_date_from" placeholder="از تاریخ"
                    class="input input-bordered input-sm w-36 text-center cursor-pointer" readonly>
                <input data-jdp id="activity_log_date_to" placeholder="تا تاریخ"
                    class="input input-bordered input-sm w-36 text-center cursor-pointer" readonly>
            </div>
        </div>

        <x-table :headers="[
            ['key' => 'user.name', 'label' => 'کاربر'],
            ['key' => 'type', 'label' => 'نوع'],
            ['key' => 'description', 'label' => 'توضیحات'],
            ['key' => 'created_at_jalali', 'label' => 'تاریخ'],
            ['key' => 'actions', 'label' => 'عملیات', 'sortable' => false],
        ]" :rows="$this->logs" with-pagination>

            @scope('cell_type', $log)
            @php
                $badgeClass = match($log->type) {
                    'created' => 'badge-success',
                    'updated' => 'badge-info',
                    'deleted' => 'badge-error',
                    'login' => 'badge-primary',
                    'logout' => 'badge-warning',
                    default => 'badge-ghost',
                };
                $label = match($log->type) {
                    'created' => 'ایجاد',
                    'updated' => 'ویرایش',
                    'deleted' => 'حذف',
                    'login' => 'ورود',
                    'logout' => 'خروج',
                    default => $log->type,
                };
            @endphp
            <x-badge :value="$label" class="{{ $badgeClass }}" rounded />
            @endscope

            @scope('cell_description', $log)
            <span class="text-sm line-clamp-1 max-w-[200px]" title="{{ $log->description }}">
                {{ Str::limit($log->description, 30, '...') }}
            </span>
            @endscope

            @scope('cell_created_at_jalali', $log)
            <span class="text-xs text-base-content/60">{{ jdate($log->created_at)->format('Y/m/d H:i') }}</span>
            @endscope

            @scope('actions', $log)
            <x-button icon="o-eye" wire:click="showDetail({{ $log->id }})" class="btn-ghost btn-sm text-info" spinner />
            @endscope
        </x-table>
    </x-card>

    {{-- مودال جزئیات --}}
    <x-modal wire:model="showModal" title="جزئیات فعالیت" separator>
        @if($this->selectedLog)
        <div class="space-y-4 text-right" dir="rtl">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-xs text-base-content/50">کاربر:</span>
                    <p class="text-sm font-bold">{{ $this->selectedLog->user->full_name ?? $this->selectedLog->user->name ?? 'نامشخص' }}</p>
                </div>
                <div>
                    <span class="text-xs text-base-content/50">نوع:</span>
                    <p class="text-sm font-bold">{{ $this->selectedLog->type }}</p>
                </div>
                <div>
                    <span class="text-xs text-base-content/50">آدرس IP:</span>
                    <p class="text-sm font-mono">{{ $this->selectedLog->ip_address ?? 'نامشخص' }}</p>
                </div>
                <div>
                    <span class="text-xs text-base-content/50">تاریخ:</span>
                    <p class="text-sm">{{ jdate($this->selectedLog->created_at)->format('Y/m/d H:i:s') }}</p>
                </div>
            </div>

            <div>
                <span class="text-xs text-base-content/50">توضیحات:</span>
                <p class="text-sm mt-1">{{ $this->selectedLog->description }}</p>
            </div>

            @if($this->selectedLog->old_values)
            <div class="bg-base-200 p-3 rounded-lg">
                <span class="text-xs text-base-content/50 font-bold">مقدار قبلی:</span>
                <pre class="text-xs mt-1 overflow-auto max-h-40">{{ json_encode($this->selectedLog->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            @endif

            @if($this->selectedLog->new_values)
            <div class="bg-primary/10 p-3 rounded-lg">
                <span class="text-xs text-primary font-bold">مقدار جدید:</span>
                <pre class="text-xs mt-1 overflow-auto max-h-40">{{ json_encode($this->selectedLog->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            @endif

            @if($this->selectedLog->user_agent)
            <div class="bg-base-200 p-3 rounded-lg">
                <span class="text-xs text-base-content/50 font-bold">User Agent:</span>
                <p class="text-[10px] mt-1 break-all opacity-60">{{ Str::limit($this->selectedLog->user_agent, 100, '...') }}</p>
            </div>
            @endif
        </div>
        @endif

        <x-slot:actions>
            <x-button label="بستن" wire:click="closeDetail" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>
</div>

@script
<script>
    const initActivityLogJdp = () => {
        if (typeof jalaliDatepicker === 'undefined') {
            return;
        }

        jalaliDatepicker.startWatch({
            time: false,
            hasSecond: false,
            format: 'YYYY/MM/DD',
            separatorChars: { date: '/', between: ' ', time: ':' },
        });

        const fromInput = document.getElementById('activity_log_date_from');
        const toInput = document.getElementById('activity_log_date_to');

        if (fromInput && !fromInput.dataset.jdpBound) {
            fromInput.dataset.jdpBound = '1';
            fromInput.addEventListener('jdp:change', e => {
                $wire.set('dateFrom', e.target.value);
            });
        }
        if (toInput && !toInput.dataset.jdpBound) {
            toInput.dataset.jdpBound = '1';
            toInput.addEventListener('jdp:change', e => {
                $wire.set('dateTo', e.target.value);
            });
        }
    };

    initActivityLogJdp();
    document.addEventListener('livewire:navigated', initActivityLogJdp);
</script>
@endscript
