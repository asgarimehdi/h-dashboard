<?php

namespace App\Livewire\Tickets;

use App\Models\Unit;
use App\Models\Ticket;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.app')]
class CreateTicket extends Component
{
    use WithFileUploads;

    public $search = '';
    public $unit_id = null;
    public $showDropdown = false;
    public $subject, $content, $priority = 'normal';
    public $files = [];

    public function selectUnit($id, $name)
    {
        $this->unit_id = $id;
        $this->search = $name;
        $this->showDropdown = false;
    }

    public function updatedSearch()
    {
        $this->unit_id = null;
        $this->showDropdown = true;
    }

    public function updatedFiles()
    {
        $this->resetErrorBag('files');
        if (count($this->files) > 5) {
            $this->addError('files', 'حداکثر ۵ فایل مجاز است.');
            $this->files = [];
            return;
        }
        $this->validate(['files.*' => 'mimes:jpg,jpeg,png,pdf,zip,rar|max:5120']);
    }

    public function removeFile($index)
    {
        if (isset($this->files[$index])) {
            unset($this->files[$index]);
            $this->files = array_values($this->files);
        }
    }

   public function saveTicket()
{
    $this->validate([
        'unit_id' => [
            'required',
            'exists:units,id',
            function ($attribute, $value, $fail) {
                if ($value == auth()->user()->person?->u_id) {
                    $fail('شما نمی‌توانید به واحد خودتان تیکت ارسال کنید.');
                }
            },
        ],
        'subject' => 'required|string|min:5|max:255',
        'content' => 'required|string|min:10',
    ]);

    $ticketCode = 'TK-' . strtoupper(substr(uniqid(), -6));

    // ۱. ایجاد تیکت
    $ticket = Ticket::create([
        'ticket_code' => $ticketCode,
        'user_id' => auth()->id(),
        'unit_id' => $this->unit_id,
        'subject' => $this->subject,
        'content' => $this->content,
        'priority' => $this->priority,
        'status' => 'created',
        'current_assignee_id' => null,
    ]);

    // ۲. ابتدا ایجاد فعالیت (تا ID آن را داشته باشیم)
    $initialActivity = $ticket->activities()->create([
        'user_id' => auth()->id(),
        'action' => 'created',
        'description' => 'تیکت ایجاد شد و به واحد ' . $ticket->unit->name . ' اختصاص یافت.',
        'to_unit_id' => $this->unit_id,
        'is_internal' => false,
    ]);

    // ۳. ثبت فایل‌ها و متصل کردن آن‌ها به فعالیت اول
    if ($this->files) {
        foreach ($this->files as $file) {
            $path = $file->store('attachments', 'public');
            $ticket->attachments()->create([
                'user_id' => auth()->id(),
                'activity_id' => $initialActivity->id, // متصل کردن فایل به اولین فعالیت
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);
        }
    }

    session()->flash('success', 'تیکت با موفقیت ثبت شد.');
    session()->flash('ticket_code', $ticketCode);

    return redirect()->route('tickets.create');
}

    public function render()
    {
        $units = [];

        if (strlen($this->search) >= 2) {
            $userUnitId = auth()->user()->person?->u_id;

            $query = Unit::where('can_receive_tickets', true)->where('is_active', true);

            if ($userUnitId) {
                $query->where('id', '!=', $userUnitId);
            }

            $units = $query->where('name', 'like', '%' . $this->search . '%')->take(5)->get();
        }

        return view('livewire.tickets.create-ticket', compact('units'));
    }
}
