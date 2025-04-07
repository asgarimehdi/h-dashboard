<?php

use App\Models\Tahsil;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;
    use Toast;

    public $name;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    public function delete(Tahsil $tahsil): void
    {
        try {
            $tahsil->delete();
            $this->warning("$tahsil->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

    public function createTahsil(Tahsil $tahsil): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:tahsils,name',
        ]);

        $tahsil::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name']);
        $this->modal = false;
    }

    public function editTahsil($id)
    {
        $tahsil = Tahsil::findOrFail($id);
        $this->editingId = $id;
        $this->name = $tahsil->name;
    }

    public function updateTahsil(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:tahsils,name,' . $this->editingId,
        ]);

        try {
            $tahsil = Tahsil::findOrFail($this->editingId);
            $tahsil->update(['name' => $this->name]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->reset(['name', 'editingId']);
            $this->modal = false;
        } catch (\Exception $e) {
            $this->error("خطا در ویرایش", position: 'toast-bottom');
        }
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell',],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'],
        ];
    }

    public function tahsils(): LengthAwarePaginator
    {
        $query = Tahsil::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $this->tahsils = $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'tahsils' => $this->tahsils(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <x-header title="مدیریت وضعیت های تحصیلی" separator progress-indicator>
        <x-slot:middle class="!justify-end"></x-slot:middle>
        <x-slot:actions>
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        <div class="breadcrumbs flex gap-2 items-center">
            <x-button class="btn-success" @click="$wire.modal = true" responsive icon="o-plus"/>
            <div class="flex-1">
                <x-input
                    placeholder="Search..."
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="w-full"
                />
            </div>
        </div>

        <x-table :headers="$headers" :rows="$tahsils" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[3, 5, 10]">
            @foreach($tahsils as $tahsil)
                <tr wire:key="{{ $tahsil->id }}">
                    <td>{{ $tahsil->id }}</td>
                    <td>{{ $tahsil->name }}</td>
                    <td>
                        @scope('actions', $tahsil)
                        <div class="flex w-1/4">
                            <x-button icon="o-pencil"
                                      wire:click="editTahsil({{ $tahsil->id }})"
                                      class="btn-ghost btn-sm text-primary"
                                      @click="$wire.modal = true">
                                <span class="hidden sm:inline">ویرایش</span>
                            </x-button>

                            <x-button icon="o-trash"
                                      wire:click="delete({{ $tahsil->id }})"
                                      wire:confirm="Are you sure?"
                                      spinner
                                      class="btn-ghost btn-sm text-error">
                                <span class="hidden sm:inline">حذف</span>
                            </x-button>
                        </div>
                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش عنوان تحصیلی' : 'ثبت عنوان تحصیلی جدید'" persistent
             separator>
        <x-form wire:submit.prevent="{{ $editingId ? 'updateTahsil' : 'createTahsil' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="عنوان تحصیلی"
                placeholder="عنوان"
                required
                icon="o-magnifying-glass"
            />

            <div class="flex gap-4">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner/>
                <x-button label="ریست" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>
