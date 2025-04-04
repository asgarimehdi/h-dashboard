<?php


use App\Models\Semat;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use Toast;
    public $name;
    public int|null $editingId = null;
    public string $search = '';
    public int $perPage = 5;
    public bool $drawer = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // Clear filters
    public function clear(): void
    {
        $this->reset();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    // Delete action
    public function delete(Semat $semat): void
    {
        $semat->delete();
        $this->warning("$semat->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
    }
    // create action
    public function createSemat(Semat $semat): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:semats,name',
        ]);

        $semat::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name']);
    }

    //edit clicked
    public function editSemat($id)
    {
        //dd($id);
        $semat = Semat::findOrFail($id);
        $this->editingId = $id;
        $this->name = $semat->name;
    }
//edit action
    public function updateSemat()
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:semats,name,' . $this->editingId,
        ]);

        $semat = Semat::findOrFail($this->editingId);
        $semat->update(['name' => $this->name]);

        $this->success("$this->name بروزرسانی شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name', 'editingId']);
    }
    // Table headers

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'name', 'label' => 'عنوان', 'class' => 'w-64'],
        ];
    }

    /**
     * For demo purpose, this is a static collection.
     *
     * On real projects you do it with Eloquent collections.
     * Please, refer to maryUI docs to see the eloquent examples.
     */


    public function semats(): LengthAwarePaginator
    {
        $query = Semat::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $this->semats = $query->paginate($this->perPage);

    }




    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'semats' => $this->semats(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="مدیریت وضعیت های سمت" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-success btn-sm" label="ثبت جدید" @click="$wire.drawer = true" responsive icon="o-plus" />

            <x-theme-selector />
        </x-slot:actions>

    </x-header>

    <!-- TABLE  -->
    <x-card shadow >
        @if (isset($editingId))
            <div class="flex items-center space-x-2">
                <x-input type="text" wire:model="name" class="flex-1 w-lg"  wire:keydown.enter="updateSemat"/>
                <x-button wire:click="updateSemat" class="btn-success btn-sm" icon="o-check"/>
                <x-button wire:click="$set('editingId', null)" class="btn-outline btn-sm" icon="o-x-mark"/>
            </div>
        @endif
        <x-table :headers="$headers" :rows="$semats" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[3, 5, 10]" @class(['opacity-10' => isset($editingId)])>>
            @foreach($semats as $semat)
                <tr wire:key="{{ $semat->id }}" >
                    <td>{{ $semat->id }}</td>

                    <td>{{ $semat->name }}</td>
                    <td>
                        @scope('actions', $semat)
                        <!-- دکمه ویرایش -->
                        <x-button icon="o-pencil" wire:click="editSemat({{ $semat->id }})"
                                  class="btn-ghost btn-sm text-primary" label="Edit" />

                        <!-- دکمه حذف -->
                        <x-button icon="o-trash" wire:click="delete({{ $semat->id }})"
                                  wire:confirm="Are you sure?" spinner
                                  class="btn-ghost btn-sm text-error" label="Delete" />


                        @endscope
                    </td>
                </tr>
            @endforeach
        </x-table>

    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="ثبت جدید" left separator with-close-button class="lg:w-1/3">
        <form wire:submit.prevent="createSemat" class="space-y-4">
            <x-input wire:model="name"
                     label="عنوان سمت"
                     placeholder="عنوان"
                     required icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-button type="submit"  label="ایجاد" icon="o-check" class="btn-primary"  spinner />
            <x-button label="ریست" icon="o-x-mark" wire:click="clear"  spinner/>

        </form>
    </x-drawer>
</div>
