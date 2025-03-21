<?php


use App\Models\Estekhdam;
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

    public bool $drawer = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // Clear filters
    public function clear(): void
    {
        $this->reset();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    // Delete action
    public function delete(Estekhdam $estekhdam): void
    {
        $estekhdam->delete();
        $this->warning("$estekhdam->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
    }
    // create action
    public function createEstekhdam(Estekhdam $estekhdam): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:estekhdams,name',
        ]);

        $estekhdam::create(['name' => $this->name]);

        $this->success("$this->name ایجاد شد ", 'با موفقیت', position: 'toast-bottom');
        $this->reset(['name']);
    }

    //edit clicked
    public function editEstekhdam($id)
    {
        //dd($id);
        $estekhdam = Estekhdam::findOrFail($id);
        $this->editingId = $id;
        $this->name = $estekhdam->name;
    }
//edit action
    public function updateEstekhdam()
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:estekhdams,name,' . $this->editingId,
        ]);

        $estekhdam = Estekhdam::findOrFail($this->editingId);
        $estekhdam->update(['name' => $this->name]);

        session()->flash('message', 'واحد با موفقیت بروزرسانی شد.');
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


    public function estekhdams(): LengthAwarePaginator
    {
        $query = Estekhdam::query();

        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $this->estekhdams = $query->paginate(5);

    }




    public function with(): array
    {
        return [
            'editingId' => $this->editingId,
            'estekhdams' => $this->estekhdams(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="مدیریت وضعیت های استخدامی" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button class="btn-success btn-sm" label="ثبت جدید" @click="$wire.drawer = true" responsive icon="o-plus" />
            <x-dropdown class="btn-sm" label="Theme" title="Theme" icon="o-swatch" >
                <x-slot:trigger>
                    <x-button icon="o-swatch" class="btn-circle btn-outline" />
                </x-slot:trigger>
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Luxury" value="luxury" />
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Valentine" value="valentine" />
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Cupcake" value="cupcake" />
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Aqua" value="aqua" />
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Dark" value="dark" />
                <x-input type="radio" name="theme-dropdown" class="theme-controller w-full btn btn-sm btn-block btn-ghost justify-start" aria-label="Light" value="light" />
            </x-dropdown>
        </x-slot:actions>

    </x-header>

    <!-- TABLE  -->
    <x-card shadow >
        @if (isset($editingId))
            <div class="flex items-center space-x-2">
                <x-input type="text" wire:model="name" class="flex-1"/>
                <x-button wire:click="updateEstekhdam" class="btn-success btn-sm" icon="o-check"/>
                <x-button wire:click="$set('editingId', null)" class="btn-outline btn-sm" icon="o-x-mark"/>
            </div>
        @endif
        <x-table :headers="$headers" :rows="$estekhdams" :sort-by="$sortBy" with-pagination>
            @foreach($estekhdams as $estekhdam)
                <tr wire:key="{{ $estekhdam->id }}" >
                    <td>{{ $estekhdam->id }}</td>

                    <td>{{ $estekhdam->name }}</td>
                    <td>
                        @scope('actions', $estekhdam)
                        <!-- دکمه ویرایش -->
                        <x-button icon="o-pencil" wire:click="editEstekhdam({{ $estekhdam->id }})"
                                  class="btn-ghost btn-sm text-primary" label="Edit" />

                        <!-- دکمه حذف -->
                        <x-button icon="o-trash" wire:click="delete({{ $estekhdam->id }})"
                                  wire:confirm="Are you sure?" spinner
                                  class="btn-ghost btn-sm text-error" label="Delete" />


                        @endscope      </td>
                </tr>
            @endforeach
        </x-table>

    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="ثبت جدید" left separator with-close-button class="lg:w-1/3">
        <form wire:submit.prevent="createEstekhdam" class="space-y-4">
            <x-input wire:model="name"
                     label="عنوان استخدامی"
                     placeholder="عنوان"
                     required icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-button type="submit"  label="ایجاد" icon="o-check" class="btn-primary"  spinner />
            <x-button label="ریست" icon="o-x-mark" wire:click="clear"  spinner/>

        </form>






    </x-drawer>
</div>
