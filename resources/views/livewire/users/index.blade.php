<?php

use App\Models\User;

use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use Toast;
    public array $expanded = [];
    public string $search = '';

    public bool $drawer = false;

    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    // Clear filters
    public function clear(): void
    {
        $this->reset();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    // Delete action
    public function delete(User $user): void
    {
        $user->delete();
        $this->warning("$user->name deleted", 'Good bye!', position: 'toast-bottom');
    }

    // Table headers

    public function headers(): array
    {
    return [
        ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
        ['key' => 'name', 'label' => 'نام', 'class' => 'w-64', 'sortable' => false],
        ['key' => 'n_code', 'label' => 'کد ملی', 'class' => 'w-32'],
    ];
    }

    /**
     * For demo purpose, this is a static collection.
     *
     * On real projects you do it with Eloquent collections.
     * Please, refer to maryUI docs to see the eloquent examples.
     */


 public function users(): LengthAwarePaginator
{
    return User::query()

        ->withAggregate('person', 'f_name')
        ->withAggregate('person', 'l_name')
       // ->withAggregate('person.tahsilat', 'tahsilat')  // دریافت میزان تحصیلات از جدول tahsilat
//        ->when($this->search, fn($q) => $q->whereHas('person', function ($query) {
//            $query->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%$this->search%"]);
//        }))
        ->when($this->search, fn(Builder $q) => $q->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%$this->search%"]))
        ->orderBy('n_code', $this->sortBy['direction'])  // مرتب‌سازی بر اساس کد ملی
        ->paginate(5);
}




    public function with(): array
    {
        return [
            'users' => $this->users(),
            'headers' => $this->headers()
        ];
    }
}; ?>

<div>
    <!-- HEADER -->
    <x-header title="Users" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search..." wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Filters" @click="$wire.drawer = true" responsive icon="o-funnel" />
            <x-theme-selector />
        </x-slot:actions>

    </x-header>

    <!-- TABLE  -->
    <x-card shadow >
   <x-table :headers="$headers" :rows="$users" :sort-by="$sortBy"  wire:model="expanded" expandable>
    @foreach($users as $user)
        <tr>
            <td>{{ $user->id }}</td>
            <td>{{ $user->person->f_name }} {{ $user->person->l_name }}</td>
            <td>{{ $user->n_code }}</td>
            <td>
               @scope('actions', $user)
        <!-- دکمه ویرایش -->
        <x-button icon="o-pencil" wire:click="edit({{ $user->id }})"
            class="btn-ghost btn-sm text-primary" label="Edit" />

        <!-- دکمه حذف -->
        <x-button icon="o-trash" wire:click="delete({{ $user->id }})"
            wire:confirm="Are you sure?" spinner
            class="btn-ghost btn-sm text-error" label="Delete" />
    @endscope      </td>
        </tr>
           @scope('expansion', $user)
           <div class="bg-base-200 p-8 font-bold">
                اطلاعات بیشتر درباره کاربر , {{ $user->name }}!
           </div>
           @endscope
    @endforeach
</x-table>

    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="Filters" right separator with-close-button class="lg:w-1/3">
        <x-input placeholder="Search..." wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="Done" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>
</div>
