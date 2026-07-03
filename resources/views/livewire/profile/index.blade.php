<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use App\Models\{User, Ticket, Todo, ActivityLog};

return new class extends Component
{
    use WithPagination;

    public $user;
    public int $totalTickets = 0;
    public int $completedTickets = 0;
    public int $pendingTickets = 0;
    public int $totalTodos = 0;
    public int $completedTodos = 0;

    public function mount(): void
    {
        $this->user = auth()->user()->load(['person', 'units']);
        $userId = auth()->id();

        $this->totalTickets = Ticket::where('user_id', $userId)->count();
        $this->completedTickets = Ticket::where('user_id', $userId)->where('status', 'completed')->count();
        $this->pendingTickets = $this->totalTickets - $this->completedTickets;

        $this->totalTodos = Todo::count();
        $this->completedTodos = Todo::where('is_completed', true)->count();
    }

    public function getUserTicketsProperty()
    {
        return Ticket::where('user_id', auth()->id())
            ->with('unit')
            ->latest()
            ->paginate(10, ['page' => 'tickets_page']);
    }

    public function getUserTodosProperty()
    {
        return Todo::latest()
            ->paginate(10, ['page' => 'todos_page']);
    }

    public function getUserActivitiesProperty()
    {
        return ActivityLog::where('user_id', auth()->id())
            ->latest()
            ->paginate(15, ['page' => 'activities_page']);
    }

    public function render()
    {
        return view('livewire.profile.index');
    }
};
