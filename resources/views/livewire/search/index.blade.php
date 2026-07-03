<?php

use Livewire\Component;
use App\Models\{Ticket, Todo, User, Unit};
use Livewire\Attributes\Layout;

return new class extends Component
{
    public string $query = '';
    public array $results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];
    public bool $hasSearched = false;

    public function updatedQuery(): void
    {
        if (strlen($this->query) < 2) {
            $this->results = ['tickets' => [], 'todos' => [], 'users' => [], 'units' => []];
            $this->hasSearched = false;
            return;
        }
        $this->search();
    }

    public function search(): void
    {
        if (strlen($this->query) < 2) return;

        $q = $this->query;
        $accessibleIds = app(\App\Services\AccessService::class)->accessibleUnitIds();

        $this->results = [
            'tickets' => Ticket::whereAccessible()
                ->where(function ($query) use ($q) {
                    $query->where('subject', 'like', "%{$q}%")
                          ->orWhere('ticket_code', 'like', "%{$q}%");
                })
                ->with(['user.person', 'unit'])
                ->latest()
                ->take(10)
                ->get()
                ->toArray(),

            'todos' => Todo::where(function ($query) use ($q) {
                    $query->where('title', 'like', "%{$q}%");
                })
                ->latest()
                ->take(10)
                ->get()
                ->toArray(),

            'users' => User::with('person')
                ->whereHas('person', function ($query) use ($q) {
                    $query->where('f_name', 'like', "%{$q}%")
                          ->orWhere('l_name', 'like', "%{$q}%");
                })
                ->take(10)
                ->get()
                ->toArray(),

            'units' => Unit::where('name', 'like', "%{$q}%")
                ->take(10)
                ->get()
                ->toArray(),
        ];

        $this->hasSearched = true;
    }

}; ?>

    <div dir="rtl">
        <x-header title="جستجو" separator progress-indicator>
            <x-slot:actions>
                <x-theme-selector/>
            </x-slot:actions>
        </x-header>
    </div>
