<?php

use App\Models\Hardware;
use App\Models\Person;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

return new class extends Component
{
    use Toast;
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Form fields
    public ?string $n_code = null;
    public ?string $pc_name = null;
    public ?string $type = null;
    public ?string $os = null;
    public ?string $ip_valid = null;
    public ?string $ip_local = null;
    public ?string $mac = null;
    public ?string $net_type = null;
    public ?string $switch = null;
    public ?string $port = null;
    public bool $shutdown = true;
    public ?string $vlan = null;
    public ?string $motherboard = null;
    public ?string $cpu = null;
    public ?string $ram = null;
    public ?string $hdd = null;
    public ?string $comments = null;
    public bool $mark = false;
    public ?string $clean_at = null;

    // Person search
    public string $personSearch = '';
    public array $personResults = [];
    public ?string $selectedPersonName = null;

    public function updatedPersonSearch(): void
    {
        if (strlen($this->personSearch) < 2) {
            $this->personResults = [];
            return;
        }

        $this->personResults = Person::where('n_code', 'LIKE', "%{$this->personSearch}%")
            ->orWhere(function ($q) {
                $q->where('f_name', 'LIKE', "%{$this->personSearch}%")
                  ->orWhere('l_name', 'LIKE', "%{$this->personSearch}%");
            })
            ->limit(10)
            ->get()
            ->map(fn($p) => ['n_code' => $p->n_code, 'name' => trim($p->f_name . ' ' . $p->l_name)])
            ->toArray();
    }

    public function selectPerson(string $nCode, string $name): void
    {
        $this->n_code = $nCode;
        $this->selectedPersonName = $name;
        $this->personSearch = '';
        $this->personResults = [];
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingId', 'showForm', 'n_code', 'pc_name', 'type', 'os',
            'ip_valid', 'ip_local', 'mac', 'net_type', 'switch', 'port',
            'shutdown', 'vlan', 'motherboard', 'cpu', 'ram', 'hdd',
            'comments', 'mark', 'clean_at', 'personSearch', 'personResults', 'selectedPersonName',
        ]);
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->reset([
            'editingId', 'n_code', 'pc_name', 'type', 'os',
            'ip_valid', 'ip_local', 'mac', 'net_type', 'switch', 'port',
            'shutdown', 'vlan', 'motherboard', 'cpu', 'ram', 'hdd',
            'comments', 'mark', 'clean_at', 'personSearch', 'personResults', 'selectedPersonName',
        ]);
        $this->showForm = true;
    }

    public function createHardware(): void
    {
        $this->validate([
            'n_code' => 'required|string|exists:persons,n_code',
            'pc_name' => 'required|string|max:255',
        ]);

        Hardware::create($this->only([
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ]));

        $this->success("سخت افزار {$this->pc_name} ایجاد شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function editHardware($id): void
    {
        $this->resetValidation();
        $hw = Hardware::findOrFail($id);
        $this->editingId = (int) $id;
        $this->fill($hw->toArray());
        $this->clean_at = $hw->clean_at?->format('Y-m-d');
        $this->selectedPersonName = $hw->person ? trim($hw->person->f_name . ' ' . $hw->person->l_name) : null;
        $this->showForm = false;
    }

    public function updateHardware(): void
    {
        $this->validate([
            'n_code' => 'required|string|exists:persons,n_code',
            'pc_name' => 'required|string|max:255',
        ]);

        $hw = Hardware::findOrFail($this->editingId);
        $hw->update($this->only([
            'n_code', 'pc_name', 'type', 'os', 'ip_valid', 'ip_local', 'mac',
            'net_type', 'switch', 'port', 'shutdown', 'vlan', 'motherboard',
            'cpu', 'ram', 'hdd', 'comments', 'mark', 'clean_at',
        ]));

        $this->success("سخت افزار {$this->pc_name} بروزرسانی شد", 'با موفقیت', position: 'toast-bottom');
        $this->cancelEdit();
    }

    public function delete(Hardware $hardware): void
    {
        try {
            $hardware->delete();
            $this->warning("سخت افزار {$hardware->pc_name} حذف شد", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error('امکان حذف وجود ندارد.', position: 'toast-bottom');
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'pc_name', 'label' => 'نام دستگاه', 'class' => ''],
            ['key' => 'person_name', 'label' => '-operator', 'class' => ''],
            ['key' => 'type', 'label' => 'نوع', 'class' => 'hidden md:table-cell'],
            ['key' => 'os', 'label' => 'سیستم عامل', 'class' => 'hidden lg:table-cell'],
            ['key' => 'ip_local', 'label' => 'IP', 'class' => 'hidden lg:table-cell'],
            ['key' => 'cpu', 'label' => 'CPU', 'class' => 'hidden xl:table-cell'],
            ['key' => 'ram', 'label' => 'RAM', 'class' => 'hidden xl:table-cell'],
            ['key' => 'hdd', 'label' => 'HDD', 'class' => 'hidden xl:table-cell'],
        ];
    }

    public function hardwares(): LengthAwarePaginator
    {
        $query = Hardware::with('person');

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('pc_name', 'LIKE', "%{$this->search}%")
                  ->orWhere('n_code', 'LIKE', "%{$this->search}%")
                  ->orWhere('ip_local', 'LIKE', "%{$this->search}%")
                  ->orWhere('mac', 'LIKE', "%{$this->search}%")
                  ->orWhere('cpu', 'LIKE', "%{$this->search}%");
            });
        }

        $query->orderBy(...array_values($this->sortBy));

        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'hardwares' => $this->hardwares()->through(fn($hw) => [
                ...$hw->toArray(),
                'person_name' => $hw->person ? trim($hw->person->f_name . ' ' . $hw->person->l_name) : '-',
            ]),
            'headers' => $this->headers(),
        ];
    }
}; ?>

<div>
    <x-header title="شناسنامه سخت افزار" separator progress-indicator>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <x-button class="btn-success" wire:click="startCreate" label="افزودن" icon="o-plus" responsive />
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

        {{-- Create Form --}}
        @if($showForm && !$editingId)
            <div class="mb-4 p-4 bg-base-200 rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    {{-- Person Search --}}
                    <div class="relative">
                        <x-input wire:model="personSearch" label="کد ملی / نام پرسنل" placeholder="جستجو..." />
                        @if(count($personResults) > 0)
                            <div class="absolute z-10 bg-base-100 border rounded-lg shadow-lg w-full mt-1 max-h-48 overflow-auto">
                                @foreach($personResults as $pr)
                                    <div class="px-3 py-2 hover:bg-base-200 cursor-pointer text-sm"
                                         wire:click="selectPerson('{{ $pr['n_code'] }}', '{{ $pr['name'] }}')">
                                        {{ $pr['name'] }} ({{ $pr['n_code'] }})
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if($selectedPersonName)
                            <div class="text-xs text-success mt-1">✓ {{ $selectedPersonName }} ({{ $n_code }})</div>
                        @endif
                        @error('n_code') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>

                    <x-input wire:model="pc_name" label="نام دستگاه" placeholder="PC-NAME" required />
                    <x-input wire:model="type" label="نوع" placeholder="pc, laptop, ..." />
                    <x-input wire:model="os" label="سیستم عامل" placeholder="Windows 10, ..." />
                    <x-input wire:model="ip_valid" label="IP عمومی" />
                    <x-input wire:model="ip_local" label="IP محلی" />
                    <x-input wire:model="mac" label="MAC Address" />
                    <x-input wire:model="net_type" label="نوع شبکه" placeholder="wireless, wired, ..." />
                    <x-input wire:model="switch" label="سوئیچ" />
                    <x-input wire:model="port" label="پورت" />
                    <x-input wire:model="vlan" label="VLAN" />
                    <x-input wire:model="motherboard" label="مادربورد" />
                    <x-input wire:model="cpu" label="CPU" />
                    <x-input wire:model="ram" label="RAM" />
                    <x-input wire:model="hdd" label="HDD/SSD" />
                    <x-input wire:model="clean_at" label="تاریخ نظافت" type="date" />
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="shutdown" class="checkbox checkbox-sm" />
                            <span class="label-text">فعال</span>
                        </label>
                    </div>
                    <div class="form-control">
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" wire:model="mark" class="checkbox checkbox-sm" />
                            <span class="label-text">علامت</span>
                        </label>
                    </div>
                    <div class="md:col-span-2 lg:col-span-3">
                        <x-input wire:model="comments" label="توضیحات" />
                    </div>
                </div>
                <div class="flex gap-2 mt-4">
                    <x-button wire:click="createHardware" label="ذخیره" icon="o-check" class="btn-primary" spinner />
                    <x-button wire:click="cancelEdit" label="لغو" icon="o-x-mark" class="btn-ghost" />
                </div>
            </div>
        @endif

        {{-- Table --}}
        <x-table :headers="$headers" :rows="$hardwares" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[5, 10, 25, 50]">
            @scope('cell_pc_name', $hw)
                @if($this->editingId === $hw['id'])
                    <input type="text" wire:model="pc_name" class="input input-bordered input-sm w-full" autofocus />
                @else
                    {{ $hw['pc_name'] }}
                @endif
            @endscope

            @scope('actions', $hw)
                <div class="flex gap-1">
                    @if($this->editingId !== $hw['id'])
                        <x-button icon="o-pencil" wire:click="editHardware({{ $hw['id'] }})" class="btn-ghost btn-sm text-primary" />
                        <x-button icon="o-trash" wire:click="delete({{ $hw['id'] }})" wire:confirm="آیا مطمئن هستید؟" spinner class="btn-ghost btn-sm text-error" />
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>
</div>
