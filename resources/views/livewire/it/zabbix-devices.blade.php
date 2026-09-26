<?php

use App\Models\ZabbixDevice;
use App\Services\ZabbixService;
use App\Traits\PersianNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    // ── form state ──
    public string $name = '';

    public string $type = ZabbixDevice::TYPE_NETWORK;

    public ?string $outItemId = null;

    public ?string $inItemId = null;

    public ?string $signalItemId = null;

    public ?string $frequencyItemId = null;

    public ?string $responseItemId = null;

    public int $initialDuration = 7200;

    public ?float $min = null;

    public ?float $max = null;

    public int $sortOrder = 0;

    public bool $isActive = true;

    // ── ui state ──
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $search = '';

    public int $perPage = 20;

    /** @var array{column: string, direction: string} */
    public array $sortBy = ['column' => 'sort_order', 'direction' => 'asc'];

    /**
     * نتیجه آخرین «تست اتصال» به ازای هر دستگاه.
     *
     * @var array<int, array{ok: bool, message: string}>
     */
    public array $connectionResults = [];

    private const SORTABLE_COLUMNS = ['id', 'name', 'type', 'sort_order'];

    /**
     * گزینه‌های فیلد «نوع» — کلیدها با option-value/option-label هم‌خوان‌اند.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function typeOptions(): array
    {
        return [
            ['value' => ZabbixDevice::TYPE_NETWORK, 'label' => 'شبکه'],
            ['value' => ZabbixDevice::TYPE_WIRELESS, 'label' => 'بی‌سیم'],
        ];
    }

    // ── validation ────────────────────────────────────────────────────────

    /**
     * آیتم‌های الزامی بسته به نوع دستگاه تغییر می‌کنند؛ بقیه nullable می‌مانند.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        $requiredId = ['required', 'regex:/^[0-9]+$/'];
        $optionalId = ['nullable', 'regex:/^[0-9]+$/'];
        $isNetwork = $this->type === ZabbixDevice::TYPE_NETWORK;
        $isWireless = $this->type === ZabbixDevice::TYPE_WIRELESS;

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:network,wireless'],
            'outItemId' => $isNetwork ? $requiredId : $optionalId,
            'inItemId' => $isNetwork ? $requiredId : $optionalId,
            'signalItemId' => $isWireless ? $requiredId : $optionalId,
            'frequencyItemId' => $isWireless ? $requiredId : $optionalId,
            'responseItemId' => $isWireless ? $requiredId : $optionalId,
            'initialDuration' => ['required', 'integer', 'min:1', 'max:604800'],
            'min' => ['nullable', 'numeric'],
            'max' => ['nullable', 'numeric'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:99999'],
            'isActive' => ['boolean'],
        ];
    }

    /** فیلدهای خالی را به null تبدیل می‌کند تا regex روی رشتهٔ خالی شکست نخورد. */
    protected function normalize(): void
    {
        foreach (['outItemId', 'inItemId', 'signalItemId', 'frequencyItemId', 'responseItemId'] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        // محدوده پیش‌فرض گیج بی‌سیم: ‎-85‎ تا ‎-45‎ دسی‌بل متر
        if ($this->type === ZabbixDevice::TYPE_WIRELESS) {
            $this->min = $this->min ?? -85;
            $this->max = $this->max ?? -45;
        }
    }

    /** با تعویض نوع دستگاه، محدوده پیش‌فرض گیج را پیشنهاد می‌دهد. */
    public function updatedType(): void
    {
        if ($this->type === ZabbixDevice::TYPE_WIRELESS && $this->min === null) {
            $this->min = -85;
        }

        if ($this->type === ZabbixDevice::TYPE_WIRELESS && $this->max === null) {
            $this->max = -45;
        }
    }

    // ── form actions ──────────────────────────────────────────────────────

    public function startCreate(): void
    {
        $this->authorize('manage_zabbix');
        $this->resetValidation();
        $this->reset([
            'name', 'type', 'outItemId', 'inItemId', 'signalItemId', 'frequencyItemId',
            'responseItemId', 'initialDuration', 'min', 'max', 'sortOrder', 'isActive', 'editingId',
        ]);
        $this->type = ZabbixDevice::TYPE_NETWORK;
        $this->initialDuration = 7200;
        $this->isActive = true;
        $this->showForm = true;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset([
            'name', 'type', 'outItemId', 'inItemId', 'signalItemId', 'frequencyItemId',
            'responseItemId', 'initialDuration', 'min', 'max', 'sortOrder', 'isActive', 'editingId', 'showForm',
        ]);
        $this->type = ZabbixDevice::TYPE_NETWORK;
        $this->initialDuration = 7200;
    }

    public function editDevice(int $id): void
    {
        $this->authorize('manage_zabbix');
        $this->resetValidation();

        $device = ZabbixDevice::query()->findOrFail($id);

        $this->editingId = $device->id;
        $this->name = $device->name;
        $this->type = $device->type;
        $this->outItemId = $device->out_item_id;
        $this->inItemId = $device->in_item_id;
        $this->signalItemId = $device->signal_item_id;
        $this->frequencyItemId = $device->frequency_item_id;
        $this->responseItemId = $device->response_item_id;
        $this->initialDuration = $device->initial_duration;
        $this->min = $device->min;
        $this->max = $device->max;
        $this->sortOrder = $device->sort_order;
        $this->isActive = $device->is_active;
        $this->showForm = true;
    }

    public function createDevice(): void
    {
        $this->authorize('manage_zabbix');
        $this->normalize();
        $this->validate();

        ZabbixDevice::query()->create([
            'name' => $this->name,
            'type' => $this->type,
            'out_item_id' => $this->outItemId,
            'in_item_id' => $this->inItemId,
            'signal_item_id' => $this->signalItemId,
            'frequency_item_id' => $this->frequencyItemId,
            'response_item_id' => $this->responseItemId,
            'initial_duration' => $this->initialDuration,
            'min' => $this->min,
            'max' => $this->max,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
        ]);

        $this->success("«{$this->name}» ثبت شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function updateDevice(): void
    {
        $this->authorize('manage_zabbix');
        $this->normalize();
        $this->validate();

        $device = ZabbixDevice::query()->findOrFail($this->editingId);

        $device->update([
            'name' => $this->name,
            'type' => $this->type,
            'out_item_id' => $this->outItemId,
            'in_item_id' => $this->inItemId,
            'signal_item_id' => $this->signalItemId,
            'frequency_item_id' => $this->frequencyItemId,
            'response_item_id' => $this->responseItemId,
            'initial_duration' => $this->initialDuration,
            'min' => $this->min,
            'max' => $this->max,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
        ]);

        $this->success("«{$this->name}» بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage_zabbix');

        $device = ZabbixDevice::query()->findOrFail($id);
        $name = $device->name;
        $device->delete();

        unset($this->connectionResults[$id]);

        $this->warning("«{$name}» حذف شد", 'با موفقیت', position: 'toast-bottom');
    }

    /** فعال/غیرفعال کردن بدون حذف ردیف. */
    public function toggle(int $id): void
    {
        $this->authorize('manage_zabbix');

        $device = ZabbixDevice::query()->findOrFail($id);
        $device->is_active = ! $device->is_active;
        $device->save();

        $this->success(
            $device->is_active ? "«{$device->name}» فعال شد" : "«{$device->name}» غیرفعال شد",
            'با موفقیت',
            position: 'toast-bottom'
        );
    }

    // ── connection test (Issue #698 / Step 5) ─────────────────────────────

    /**
     * شناسه‌های آیتم دستگاه را در زبیکس بررسی می‌کند.
     *
     * هیچ‌وقت exception پرتاب نمی‌کند: خطای زبیکس هم مثل TrafficController
     * به پیام قابل نمایش تبدیل می‌شود تا صفحه 500 نشود.
     */
    public function testConnection(int $id): void
    {
        $this->authorize('manage_zabbix');

        $device = ZabbixDevice::query()->findOrFail($id);
        $itemIds = $device->itemIds();

        if ($itemIds === []) {
            $this->connectionResults[$id] = ['ok' => false, 'message' => 'شناسه آیتمی ثبت نشده است.'];

            return;
        }

        try {
            $values = app(ZabbixService::class)->getLatestValues($itemIds);

            $missing = [];
            foreach ($values as $itemId => $value) {
                if ($value === null) {
                    $missing[] = (string) $itemId;
                }
            }

            if ($missing !== []) {
                $this->connectionResults[$id] = [
                    'ok' => false,
                    'message' => 'آیتم یافت نشد: '.implode('، ', $missing),
                ];

                return;
            }

            $this->connectionResults[$id] = ['ok' => true, 'message' => 'اتصال برقرار است'];
        } catch (\Throwable $e) {
            $this->connectionResults[$id] = ['ok' => false, 'message' => 'خطا در اتصال: '.$e->getMessage()];
        }
    }

    // ── listing ───────────────────────────────────────────────────────────

    public function typeLabel(string $type): string
    {
        return match ($type) {
            ZabbixDevice::TYPE_NETWORK => 'شبکه',
            ZabbixDevice::TYPE_WIRELESS => 'بی‌سیم',
            default => $type,
        };
    }

    /**
     * شناسه‌های آیتم ثبت‌شده برای نمایش در جدول.
     *
     * @return array<int, string>
     */
    public function itemIdsFor(ZabbixDevice $device): array
    {
        return $device->itemIds();
    }

    /** @return array<int, array<string, mixed>> */
    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'],
            ['key' => 'type', 'label' => 'نوع'],
            ['key' => 'item_ids', 'label' => 'شناسه آیتم‌ها', 'sortable' => false],
            ['key' => 'sort_order', 'label' => 'ترتیب'],
            ['key' => 'is_active', 'label' => 'وضعیت', 'sortable' => false],
        ];
    }

    /** @return LengthAwarePaginator<int, ZabbixDevice> */
    public function devices(): LengthAwarePaginator
    {
        $query = ZabbixDevice::query()->ordered();

        if ($this->search !== '') {
            $needle = '%'.PersianNormalizer::normalizeForQuery($this->search).'%';
            $query->where('name', 'like', $needle);
        }

        $column = in_array($this->sortBy['column'], self::SORTABLE_COLUMNS, true)
            ? $this->sortBy['column']
            : 'sort_order';
        $direction = $this->sortBy['direction'] === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);

        return $query->paginate($this->perPage);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'devices' => $this->devices(),
            'headers' => $this->headers(),
        ];
    }
};
?>

<div>
    <x-header title="مدیریت دستگاه‌های زبیکس" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" label="دستگاه جدید" icon="o-plus" responsive />
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

        @if($showForm)
            <div class="flex flex-col gap-3 mb-4 p-3 bg-base-200 rounded-lg">
                <div class="flex flex-col sm:flex-row gap-3 flex-wrap">
                    <div class="flex-1 min-w-48">
                        <x-input wire:model="name" label="عنوان" placeholder="نام دستگاه" required />
                        @error('name') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div class="w-full sm:w-40">
                        {{-- option-value/option-label are required: MaryUI defaults to
                             optionValue='id' / optionLabel='name', so 'value'/'label'
                             keys render empty options (an unreadable blank select). --}}
                        <x-select
                            wire:model="type"
                            label="نوع"
                            class="text-base-content"
                            :options="$this->typeOptions()"
                            option-value="value"
                            option-label="label"
                        />
                    </div>
                    <div class="w-full sm:w-32">
                        <x-input wire:model="sortOrder" label="ترتیب" type="number" min="0" />
                    </div>
                    <div class="w-full sm:w-32 self-end pb-2">
                        <x-toggle wire:model="isActive" label="فعال" />
                    </div>
                </div>

                @if($type === 'network')
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1">
                            <x-input wire:model="outItemId" label="شناسه آیتم خروجی" placeholder="مثلاً 73638" />
                            @error('outItemId') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex-1">
                            <x-input wire:model="inItemId" label="شناسه آیتم ورودی" placeholder="مثلاً 73494" />
                            @error('inItemId') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="w-full sm:w-40">
                            <x-input wire:model="initialDuration" label="بازه پیش‌فرض (ثانیه)" type="number" min="1" />
                            @error('initialDuration') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1">
                            <x-input wire:model="signalItemId" label="شناسه سیگنال" />
                            @error('signalItemId') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex-1">
                            <x-input wire:model="frequencyItemId" label="شناسه فرکانس" />
                            @error('frequencyItemId') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="flex-1">
                            <x-input wire:model="responseItemId" label="شناسه زمان پاسخ" />
                            @error('responseItemId') <span class="text-error text-xs">{{ $message }}</span> @enderror
                        </div>
                        <div class="w-full sm:w-28">
                            <x-input wire:model="min" label="حداقل" type="number" />
                        </div>
                        <div class="w-full sm:w-28">
                            <x-input wire:model="max" label="حداکثر" type="number" />
                        </div>
                    </div>
                @endif

                <div class="flex gap-2">
                    <x-button
                        wire:click="{{ $editingId ? 'updateDevice' : 'createDevice' }}"
                        label="ذخیره"
                        icon="o-check"
                        class="btn-primary"
                        spinner
                    />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        <x-table
            :headers="$headers"
            :rows="$devices"
            :sort-by="$sortBy"
            with-pagination
            per-page="perPage"
            :per-page-values="[10, 20, 50]">

            @scope('cell_type', $device)
                {{ $this->typeLabel($device->type) }}
            @endscope

            @scope('cell_item_ids', $device)
                <div class="flex flex-wrap gap-1" dir="ltr">
                    @foreach($this->itemIdsFor($device) as $itemId)
                        <span class="badge badge-ghost badge-sm font-mono">{{ $itemId }}</span>
                    @endforeach
                </div>
            @endscope

            @scope('cell_is_active', $device)
                @if($device->is_active)
                    <span class="badge badge-success badge-sm">فعال</span>
                @else
                    <span class="badge badge-ghost badge-sm">غیرفعال</span>
                @endif
            @endscope

            @scope('actions', $device)
                <div class="flex gap-1 items-center">
                    @php $result = $connectionResults[$device->id] ?? null; @endphp
                    @if($result !== null)
                        <span class="{{ $result['ok'] ? 'badge badge-success' : 'badge badge-error' }} badge-sm whitespace-nowrap"
                              title="{{ $result['message'] }}">
                            {{ $result['ok'] ? 'اتصال برقرار' : 'خطا' }}
                        </span>
                    @endif
                    <x-button
                        icon="o-signal"
                        wire:click="testConnection({{ $device->id }})"
                        class="btn-ghost btn-circle btn-sm"
                        title="تست اتصال"
                        spinner
                    />
                    @if($this->editingId !== $device->id)
                        <x-button
                            icon="o-pencil"
                            wire:click="editDevice({{ $device->id }})"
                            class="btn-ghost btn-circle btn-sm text-primary"
                            title="ویرایش"
                        />
                        <x-button
                            icon="{{ $device->is_active ? 'o-eye-slash' : 'o-eye' }}"
                            wire:click="toggle({{ $device->id }})"
                            class="btn-ghost btn-circle btn-sm"
                            title="{{ $device->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}"
                        />
                        <x-button
                            icon="o-trash"
                            wire:click="delete({{ $device->id }})"
                            wire:confirm="آیا مطمئن هستید؟"
                            spinner
                            class="btn-ghost btn-circle btn-sm text-error"
                            title="حذف"
                        />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
