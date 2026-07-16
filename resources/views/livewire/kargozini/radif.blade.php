<?php

use App\Models\Radif;
use Livewire\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

return new class extends Component {
    use WithPagination;
    use Toast;

    public $name;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $modal = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // Clear filters - Resets form fields and closes modal if called from modal button
    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom');
    }

    // Delete action
    public function delete(Radif $radif): void
    {
        try {
            $radif->delete();
            $this->warning("$radif->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
        } catch (\Exception $e) {
            $this->error("امکان حذف وجود ندارد زیرا در جدول دیگری استفاده شده است.", position: 'toast-bottom');
        }
    }

    // create action
    public function createRadif(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:radifs,name',
        ]);

        Radif::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name']);
        $this->modal = false;
    }

    //edit clicked - Prepares data for the modal
    public function editRadif($id): void
    {
        $radif = Radif::findOrFail($id);
        $this->editingId = $id;
        $this->name = $radif->name;
    }

    //edit action
    public function updateRadif(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:radifs,name,' . $this->editingId,
        ]);
        
        try {
            $radif = Radif::findOrFail($this->editingId);
            $radif->update(['name' => $this->name]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->reset(['name', 'editingId']);
            $this->modal = false;
        } catch (\Exception $e) {
            $this->error("خطا در ویرایش", position: 'toast-bottom');
        }
    }

    // Table headers
    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'],
        ];
    }

    // Data retrieval
    public function radifs(): LengthAwarePaginator
    {
        $query = Radif::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        
        $query->orderBy(...array_values($this->sortBy));
        return $query->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'radifs' => $this->radifs(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="مدیریت وضعیت های ردیف سازمانی" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            {{-- Search moved below --}}
        </x-slot:middle>
        <x-slot:actions>
            {{-- Create button moved below --}}
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <!-- TABLE  -->
    <x-card shadow>
        {{-- Search and Create Button Area --}}
        <div class="flex gap-2 items-center mb-4">
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

        <x-table :headers="$headers" :rows="$radifs" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[3, 5, 10]">

            @foreach($radifs as $radif)
                <tr wire:key="{{ $radif->id }}">
                    @scope('actions', $radif)
                    <div class="flex">
                        <x-button
                            icon="o-pencil"
                            wire:click="editRadif({{ $radif->id }})"
                            class="btn-ghost btn-sm text-primary"
                            @click="$wire.modal = true"
                        />

                        <x-button
                            icon="o-trash"
                            wire:click="delete({{ $radif->id }})"
                            wire:confirm="آیا مطمئن هستید؟"
                            spinner
                            class="btn-ghost btn-sm text-error"
                        />
                    </div>
                    @endscope
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش عنوان ردیف' : 'ثبت عنوان ردیف جدید'" persistent separator>
        <x-form wire:submit.prevent="{{ $editingId ? 'updateRadif' : 'createRadif' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="عنوان ردیف"
                placeholder="عنوان"
                required
                icon="o-magnifying-glass"
            />

            <div class="flex gap-4 justify-end">
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner />
                <x-button label="ریست" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>