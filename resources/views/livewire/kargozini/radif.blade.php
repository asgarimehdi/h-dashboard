<?php

use App\Models\Radif;

// Changed from Estekhdam
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
    public bool $modal = false; // Changed from $drawer to $modal

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // Clear filters - Resets form fields and closes modal if called from modal button
    public function clear(): void
    {
        $this->reset();
        $this->info('فیلدها خالی شدند', position: 'toast-bottom'); // Changed message to Persian
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
    public function createRadif(): void // Removed Radif type hint injection, it's not needed here
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:radifs,name',
        ]);

        Radif::create(['name' => $this->name]); // Directly use the model

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name']); // Only reset the form field
        $this->modal = false; // Close modal after creation
    }

    //edit clicked - Prepares data for the modal
    public function editRadif($id)
    {
        $radif = Radif::findOrFail($id);
        $this->editingId = $id;
        $this->name = $radif->name;
        // The modal will be opened by @click in the view
    }

    //edit action
    public function updateRadif(): void // Removed Radif type hint injection
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:radifs,name,' . $this->editingId,
        ]);
        try {
            $radif = Radif::findOrFail($this->editingId);
            $radif->update(['name' => $this->name]);

            $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
            $this->reset(['name', 'editingId']); // Reset form fields and editing state
            $this->modal = false; // Close modal after update
        } catch (\Exception $e) {
            $this->error("خطا در ویرایش", position: 'toast-bottom'); // Generic error message
        }
    }

    // Table headers
    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1 hidden sm:table-cell'], // Matched style
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'flex-1'], // Matched style
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
        return $this->radifs = $query->paginate($this->perPage);
        // Removed redundant assignment: return $query->paginate($this->perPage);
    }


    public function with(): array
    {
        return [
            'editingId' => $this->editingId, // Not strictly needed to pass if only used internally and in modal logic
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
        <div class="flex gap-2 items-center mb-4"> {{-- Added margin-bottom --}}
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

        {{-- Removed inline editing block --}}
        {{-- Removed opacity class from table --}}
        <x-table :headers="$headers" :rows="$radifs" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[3, 5, 10]">

            @foreach($radifs as $radif)
                {{-- Use wire:key for efficient DOM updates --}}
                <tr wire:key="{{ $radif->id }}">
                    {{-- Data cells are handled by :rows and :headers --}}
                    {{-- Actions Scope --}}
                    @scope('actions', $radif)
                    <div class="flex"> {{-- Removed w-1/4, let it auto-size --}}
                        <!-- دکمه ویرایش -->
                        <x-button
                            icon="o-pencil"
                            wire:click="editRadif({{ $radif->id }})"
                            class="btn-ghost btn-sm text-primary"
                            @click="$wire.modal = true" {{-- Open modal on click --}}
                        >
                            <span class="hidden sm:inline">ویرایش</span>
                        </x-button>

                        <!-- دکمه حذف -->
                        <x-button
                            icon="o-trash"
                            wire:click="delete({{ $radif->id }})"
                            wire:confirm="آیا مطمئن هستید؟" {{-- Persian confirm message --}}
                            spinner
                            class="btn-ghost btn-sm text-error"
                        >
                            <span class="hidden sm:inline">حذف</span>
                        </x-button>
                    </div>
                    @endscope
                </tr>
            @endforeach
        </x-table>
    </x-card>

    {{-- Swapped Drawer for Modal --}}
    <x-modal wire:model="modal" :title="$editingId ? 'ویرایش عنوان ردیف' : 'ثبت عنوان ردیف جدید'" persistent separator>

        {{-- Form points to dynamic method based on editingId --}}
        <x-form wire:submit.prevent="{{ $editingId ? 'updateRadif' : 'createRadif' }}" class="grid gap-4">
            <x-input
                wire:model="name"
                label="عنوان ردیف"
                placeholder="عنوان"
                required
                icon="o-magnifying-glass" {{-- Changed icon to match file 1 --}}
            />

            <div class="flex gap-4 justify-end"> {{-- Matched button layout --}}
                <x-button type="submit" label="ذخیره" icon="o-check" class="btn-primary pl-6" spinner />
                <x-button label="ریست" icon="o-x-mark" wire:click="clear" class="btn-default pl-6" spinner/>
            </div>
        </x-form>
    </x-modal>
</div>
