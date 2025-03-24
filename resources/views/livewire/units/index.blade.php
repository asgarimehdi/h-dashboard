<?php
use App\Models\Unit;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
new class extends Component {
    use WithPagination;
    use Toast;
    public string $search = '';

    public int $perPage = 10;

    public bool $drawer = false;

    public array $sortBy = ['column' => 'id', 'direction' => 'asc'];

    // Delete action
    public function delete(Unit $unit): void
    {
        $unit->delete();
        $this->warning("$unit->name حذف شد ", 'با موفقیت', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
            ['key' => 'name', 'label' => 'Name', 'class' => 'w-48'],
            ['key' => 'unitType.name', 'label' => 'Type', 'class' => 'w-16', 'sortable' => false], // table name bug
            ['key' => 'province_name', 'label' => 'Province', 'class' => 'w-16'],
            ['key' => 'county_name', 'label' => 'County', 'class' => 'w-16'],
            ['key' => 'parent_name', 'label' => 'Parent Unit', 'class' => 'w-16'],
        ];
    }
    public function units(): LengthAwarePaginator
    {
        $query = Unit::query()
            ->withAggregate('province', 'name')
            ->withAggregate('county', 'name')
            ->withAggregate('parent', 'name')
            ->withAggregate('unitType', 'name');
        if (!empty($this->search)) {
            $query->where('name', 'LIKE', '%' . $this->search . '%');
        }
        $query->orderBy(...array_values($this->sortBy));
        return $this->units = $query->paginate($this->perPage);
    }
    public function with(): array
    {
        return [
            'units' => $this->units(),
            'headers' => $this->headers()
        ];
    }
    public function updatedPerPage()
    {
        $this->resetPage();
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
        <x-table :headers="$headers" :rows="$units" :sort-by="$sortBy" with-pagination per-page="perPage"
                 :per-page-values="[10, 50, 100]">
            @foreach($units as $unit)
                <tr>
{{--                    <td>{{ $unit->id }}</td>--}}
{{--                    <td>{{ $unit->name }}</td>--}}
{{--                    <td>{{ $unit->unitType  }}</td>--}}
{{--                    <td>{{ $unit->province ? $unit->province->name : '-' }}</td>--}}
{{--                    <td>{{ $unit->county ? $unit->county->name : '-' }}</td>--}}
{{--                    <td>{{ $unit->parent ? $unit->parent->name . ' (' . ($unit->parent->unitType ? $unit->parent->unitType->name : '-') . ')' : '-' }}</td>--}}
                    <td>
                        @scope('actions', $unit)
                        <!-- دکمه ویرایش -->
                        <x-button icon="o-pencil" wire:click="#"
                                  class="btn-ghost btn-sm text-primary" />

                        <!-- دکمه حذف -->
                        <x-button icon="o-trash" wire:click="delete({{ $unit->id }})"
                                  wire:confirm="Are you sure?" spinner
                                  class="btn-ghost btn-sm text-error" />
                        @endscope
                    </td>
                </tr>

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
