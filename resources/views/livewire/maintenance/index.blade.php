<?php

use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public string $title = '';
    public string $frequency = 'monthly';
    public int $recurrenceInterval = 1;
    public ?int $unitId = null;

    public ?int $editingId = null;
    public string $search = '';
    public int $perPage = 20;
    public bool $showForm = false;
    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    private const SORTABLE_COLUMNS = ['id', 'title', 'frequency', 'next_due_at'];

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset(['title', 'frequency', 'recurrenceInterval', 'unitId', 'editingId', 'showForm']);
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->reset(['title', 'frequency', 'recurrenceInterval', 'unitId', 'editingId']);
        $this->frequency = 'monthly';
        $this->recurrenceInterval = 1;
        $this->showForm = true;
    }

    public function delete(MaintenanceSchedule $schedule): void
    {
        $this->authorize('manage_hardware');

        if (! $this->assertScheduleInScope($schedule)) {
            return;
        }

        try {
            $schedule->delete();
            $this->warning("«{$schedule->title}» حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد.', position: 'toast-bottom');
        }
    }

    public function createSchedule(): void
    {
        $this->authorize('manage_hardware');

        $this->validate([
            'title' => 'required|string|max:255',
            'frequency' => 'required|in:daily,weekly,monthly',
            'recurrenceInterval' => 'required|integer|min:1',
            'unitId' => 'required|exists:units,id',
        ]);

        // Creating inside a unit the actor cannot reach would plant a schedule
        // the `maintenance:generate-due` cron later fires tickets for.
        if (! $this->assertSubmittedUnitInScope()) {
            return;
        }

        $nextDue = $this->calculateNextDue();

        MaintenanceSchedule::create([
            'title' => $this->title,
            'frequency' => $this->frequency,
            'recurrence_interval' => $this->recurrenceInterval,
            'unit_id' => $this->unitId,
            'next_due_at' => $nextDue,
        ]);

        $this->success("«{$this->title}» ایجاد شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function editSchedule(int $id): void
    {
        $this->authorize('manage_hardware');

        $schedule = MaintenanceSchedule::findOrFail($id);

        if (! $this->assertScheduleInScope($schedule)) {
            return;
        }

        $this->resetValidation();
        $this->editingId = $id;
        $this->title = $schedule->title;
        $this->frequency = $schedule->frequency;
        $this->recurrenceInterval = $schedule->recurrence_interval;
        $this->unitId = $schedule->unit_id;
        $this->showForm = false;
    }

    public function updateSchedule(): void
    {
        $this->authorize('manage_hardware');

        $this->validate([
            'title' => 'required|string|max:255',
            'frequency' => 'required|in:daily,weekly,monthly',
            'recurrenceInterval' => 'required|integer|min:1',
            'unitId' => 'required|exists:units,id',
        ]);

        $schedule = MaintenanceSchedule::findOrFail($this->editingId);

        // Both halves matter: the loaded record must be in scope (else a scoped
        // user edits another unit's row) AND the submitted unit must be in
        // scope (else the same user relocates a row across the boundary).
        if (! $this->assertScheduleInScope($schedule)) {
            return;
        }

        if (! $this->assertSubmittedUnitInScope()) {
            return;
        }

        try {
            $schedule->update([
                'title' => $this->title,
                'frequency' => $this->frequency,
                'recurrence_interval' => $this->recurrenceInterval,
                'unit_id' => $this->unitId,
                'next_due_at' => $this->calculateNextDue(),
            ]);

            $this->success("«{$this->title}» بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
            $this->cancelEdit();
        } catch (\Exception $e) {
            $this->error('خطا در ویرایش', position: 'toast-bottom');
        }
    }

    public function titleError(): ?string
    {
        return $this->getErrorBag()->first('title');
    }

    /**
     * گزینه‌های فیلد «دوره» — کلیدها با option-value/option-label هم‌خوان‌اند.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function frequencyOptions(): array
    {
        return [
            ['value' => 'daily', 'label' => 'روزانه'],
            ['value' => 'weekly', 'label' => 'هفتگی'],
            ['value' => 'monthly', 'label' => 'ماهانه'],
        ];
    }

    public function frequencyLabel(string $freq): string
    {
        return match ($freq) {
            'daily' => 'روزانه',
            'weekly' => 'هفتگی',
            'monthly' => 'ماهانه',
            default => $freq,
        };
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'title', 'label' => 'عنوان', 'class' => 'flex-1'],
            ['key' => 'frequency', 'label' => 'دوره'],
            ['key' => 'next_due_at', 'label' => 'سررسید بعدی'],
            ['key' => 'is_overdue', 'label' => 'وضعیت', 'sortable' => false],
        ];
    }

    /**
     * @return array<int>
     */
    private function accessibleUnitIds(): array
    {
        return app(AccessService::class)->accessibleUnitIds();
    }

    /**
     * آیا کاربر فعلی دامنه‌ی سازمانی نامحدود دارد؟
     *
     * تنها استثنای قاعده‌ی «رکورد null-unit فقط برای مدیر سیستم»: برنامه‌های
     * سراسری (`unit_id = null`) ذاتاً متعلق به کل سازمان‌اند و کسی جز مدیر
     * سیستم نباید بتواند آن‌ها را ببیند یا تغییر دهد.
     */
    private function hasUnrestrictedScope(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('admin');
    }

    /**
     * آیا این زمانبندی در دامنه‌ی کاربر فعلی هست؟
     *
     * - واحد null (سراسری): فقط مدیر سیستم.
     * - واحد مشخص: واحد باید در دامنه‌ی دسترسی کاربر باشد.
     * - واحد ناموجود (FK روی delete set null شده): فقط مدیر سیستم.
     *
     * قاعده عمداً «بی‌احتیاط بسته» است: دامنه‌ی خالی یعنی `false`، نه دسترسی
     * آزاد (همان دام fail-open که در #819 باعث نشت کل سازمان شد).
     */
    private function isScheduleInScope(MaintenanceSchedule $schedule): bool
    {
        if ($schedule->unit_id === null) {
            return $this->hasUnrestrictedScope();
        }

        return in_array($schedule->unit_id, $this->accessibleUnitIds(), true);
    }

    /**
     * بررسی دامنه‌ی رکورد بارگذاری‌شده؛ در صورت خروج، پیام مناسب می‌دهد.
     */
    private function assertScheduleInScope(MaintenanceSchedule $schedule): bool
    {
        if ($this->isScheduleInScope($schedule)) {
            return true;
        }

        $this->error('شما به این زمانبندی دسترسی ندارید.', position: 'toast-bottom');

        return false;
    }

    /**
     * بررسی دامنه‌ی واحد ارسالی فرم.
     *
     * واحد مشخص باید در دامنه باشد وگرنه فرم رکورد را به واحدی منتقل می‌کند
     * که کاربر به آن دسترسی ندارد — و همان‌جا cron تیکت تولید می‌کند.
     *
     * ایشو #955 (تصمیم الف): برنامه‌ی سراسری بدون واحد نیاز محصول نیست، پس
     * اعتبارسنجی فرم `unitId` را required می‌خواهد و `null` هیچ‌وقت از آن
     * عبور نمی‌کند. این متد برای `null` همچنان خطا می‌دهد (دفاع در عمق) و
     * رکوردهای سراسری قدیمی فقط قابل نمایش‌اند، نه قابل ساخت.
     */
    private function assertSubmittedUnitInScope(): bool
    {
        if ($this->hasUnrestrictedScope()) {
            return true;
        }

        if ($this->unitId !== null && in_array($this->unitId, $this->accessibleUnitIds(), true)) {
            return true;
        }

        $this->error('شما به این واحد دسترسی ندارید.', position: 'toast-bottom');

        return false;
    }

    /**
     * گزینه‌های انتخابگر واحد — فقط واحدهای در دامنه
     *
     * کلیدها 'value'/'label' هستند، پهنای Blade باید صریحاً
     * option-value/option-label بگیرد: پیش‌فرض MaryUI روی 'id'/'name'
     * است و با رشته/آرایه، `data_get()` مقدار null برمی‌گرداند و همه‌ی
     * گزینه‌ها خالی رندر می‌شوند (همان ریشه‌ی #706).
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function unitOptions(): array
    {
        $names = Unit::query()
            ->whereIn('id', $this->accessibleUnitIds())
            ->orderBy('name')
            ->pluck('name', 'id');

        $options = [];
        foreach ($names as $id => $name) {
            $options[] = ['value' => (int) $id, 'label' => (string) $name];
        }

        return $options;
    }

    public function schedules(): LengthAwarePaginator
    {
        $query = MaintenanceSchedule::query();

        // پیش‌وند بی‌قیدوشر: دامنه‌ی خالی نباید پیش‌وند را حذف کند و کل
        // سازمان را برگرداند. واحدهای در دامنه به‌علاوه‌ی رکوردهای null-unit.
        $query->where(function ($q) {
            $q->whereNull('unit_id');

            if (! empty($this->accessibleUnitIds())) {
                $q->orWhereIn('unit_id', $this->accessibleUnitIds());
            }
        });

        if (!empty($this->search)) {
            $query->where('title', 'LIKE', '%' . $this->search . '%');
        }

        $column = in_array($this->sortBy['column'] ?? '', self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column'] : 'id';
        $direction = ($this->sortBy['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'schedules' => $this->schedules(),
            'headers' => $this->headers(),
            // انتخابگر واحد فقط واحدهای در دامنه
            'unitOptions' => $this->unitOptions(),
        ];
    }

    private function calculateNextDue(): \Carbon\CarbonInterface
    {
        return match ($this->frequency) {
            'daily' => now()->addDays($this->recurrenceInterval),
            'weekly' => now()->addWeeks($this->recurrenceInterval),
            'monthly' => now()->addMonths($this->recurrenceInterval),
            default => now()->addMonth(),
        };
    }
}; ?>

<div>
    <x-header title="زمانبندی تعمیر و نگهداری" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-ui.icon-button name="برنامه نگهداری جدید" label="برنامه نگهداری جدید" class="btn-success" wire:click="startCreate" responsive icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="جستجو..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        @if($showForm && !$editingId)
            <div class="flex flex-col gap-3 mb-4 p-3 bg-base-200 rounded-lg">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <x-input wire:model="title" label="عنوان" placeholder="عنوان برنامه" required />
                        @error('title') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="w-full sm:w-40">
                        {{-- option-value/option-label required: MaryUI defaults to
                             optionValue='id' / optionLabel='name', so 'value'/'label'
                             keys would render empty options. --}}
                        <x-select
                            wire:model="frequency"
                            label="دوره"
                            :options="$this->frequencyOptions()"
                            option-value="value"
                            option-label="label"
                        />
                    </div>
                    <div class="w-full sm:w-32">
                        <x-input wire:model="recurrenceInterval" label="هر" type="number" min="1" />
                    </div>
                    <div class="w-full sm:w-48">
                        {{-- option-value/option-label required: MaryUI defaults to
                             optionValue='id' / optionLabel='name', so the
                             'value'/'label' keys would render empty options. --}}
                        <x-select
                            wire:model="unitId"
                            label="واحد"
                            :options="$unitOptions"
                            option-value="value"
                            option-label="label"
                            placeholder="انتخاب واحد"
                        />
                    </div>
                </div>
                <div class="flex gap-2">
                    <x-button wire:click="createSchedule" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        <x-table
            :headers="$headers"
            :rows="$schedules"
            :sort-by="$sortBy"
            with-pagination
            per-page="perPage"
            :per-page-values="[10, 20, 50]">

            @scope('cell_frequency', $schedule)
                {{ $this->frequencyLabel($schedule->frequency) }}
            @endscope

            @scope('cell_next_due_at', $schedule)
                {{ $schedule->next_due_at?->format('Y/m/d') ?? '—' }}
            @endscope

            @scope('cell_is_overdue', $schedule)
                @if($schedule->isDue())
                    <span class="badge badge-warning badge-sm">سررسید شده</span>
                @else
                    <span class="badge badge-success badge-sm">فعال</span>
                @endif
            @endscope

            @scope('cell_title', $schedule)
                @if($this->editingId === $schedule->id)
                    <div class="flex gap-2 items-center">
                        <input
                            type="text"
                            wire:model="title"
                            wire:keydown.enter="updateSchedule"
                            class="input input-bordered input-sm flex-1"
                            autofocus
                        />
                        <x-ui.icon-button name="ذخیره برنامه نگهداری" icon="o-check" wire:click="updateSchedule" class="btn-ghost btn-sm text-success" spinner />
                        <x-ui.icon-button name="انصراف از ویرایش" icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                    </div>
                    @if($this->titleError()) <span class="text-error text-xs">{{ $this->titleError() }}</span> @endif
                @else
                    {{ $schedule->title }}
                @endif
            @endscope

            @scope('actions', $schedule)
                <div class="flex gap-1">
                    @if($this->editingId !== $schedule->id)
                        <x-ui.icon-button name="ویرایش برنامه نگهداری"
                            icon="o-pencil"
                            wire:click="editSchedule({{ $schedule->id }})"
                            class="btn-ghost btn-sm text-primary"
                        />
                        <x-ui.icon-button name="حذف برنامه نگهداری"
                            icon="o-trash"
                            wire:click="delete({{ $schedule->id }})"
                            wire:confirm="آیا مطمئن هستید؟"
                            spinner
                            class="btn-ghost btn-sm text-error"
                        />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
