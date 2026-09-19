<?php

use App\Models\MaintenanceSchedule;
use App\Models\Unit;
use Illuminate\Pagination\LengthAwarePaginator;
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
        try {
            $schedule->delete();
            $this->warning("«{$schedule->title}» حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد.', position: 'toast-bottom');
        }
    }

    public function createSchedule(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'frequency' => 'required|in:daily,weekly,monthly',
            'recurrenceInterval' => 'required|integer|min:1',
            'unitId' => 'nullable|exists:units,id',
        ]);

        $nextDue = match ($this->frequency) {
            'daily' => now()->addDays($this->recurrenceInterval),
            'weekly' => now()->addWeeks($this->recurrenceInterval),
            'monthly' => now()->addMonths($this->recurrenceInterval),
        };

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
        $this->resetValidation();
        $schedule = MaintenanceSchedule::findOrFail($id);
        $this->editingId = $id;
        $this->title = $schedule->title;
        $this->frequency = $schedule->frequency;
        $this->recurrenceInterval = $schedule->recurrence_interval;
        $this->unitId = $schedule->unit_id;
        $this->showForm = false;
    }

    public function updateSchedule(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'frequency' => 'required|in:daily,weekly,monthly',
            'recurrenceInterval' => 'required|integer|min:1',
            'unitId' => 'nullable|exists:units,id',
        ]);

        try {
            $schedule = MaintenanceSchedule::findOrFail($this->editingId);
            $schedule->update([
                'title' => $this->title,
                'frequency' => $this->frequency,
                'recurrence_interval' => $this->recurrenceInterval,
                'unit_id' => $this->unitId,
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
            ['key' => 'is_overdue', 'label' => 'وضعیت'],
        ];
    }

    public function schedules(): LengthAwarePaginator
    {
        $query = MaintenanceSchedule::query();

        if (!empty($this->search)) {
            $query->where('title', 'LIKE', '%' . $this->search . '%');
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'schedules' => $this->schedules(),
            'headers' => $this->headers(),
            'units' => Unit::orderBy('name')->pluck('name', 'id'),
        ];
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
            <x-button class="btn-success" wire:click="startCreate" responsive icon="o-plus"/>
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
                        <x-select wire:model="frequency" label="دوره" :options="[
                            ['value' => 'daily', 'label' => 'روزانه'],
                            ['value' => 'weekly', 'label' => 'هفتگی'],
                            ['value' => 'monthly', 'label' => 'ماهانه'],
                        ]" />
                    </div>
                    <div class="w-full sm:w-32">
                        <x-input wire:model="recurrenceInterval" label="هر" type="number" min="1" />
                    </div>
                    <div class="w-full sm:w-48">
                        <x-select wire:model="unitId" label="واحد" :options="$units->prepend('— همه —', null)" />
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
                        <x-button icon="o-check" wire:click="updateSchedule" class="btn-ghost btn-sm text-success" spinner />
                        <x-button icon="o-x-mark" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                    </div>
                    @if($this->titleError()) <span class="text-error text-xs">{{ $this->titleError() }}</span> @endif
                @else
                    {{ $schedule->title }}
                @endif
            @endscope

            @scope('actions', $schedule)
                <div class="flex gap-1">
                    @if($this->editingId !== $schedule->id)
                        <x-button
                            icon="o-pencil"
                            wire:click="editSchedule({{ $schedule->id }})"
                            class="btn-ghost btn-sm text-primary"
                        />
                        <x-button
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
