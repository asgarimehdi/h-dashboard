<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\Zone;
use App\Models\Unit;
use App\Services\AccessService;
use Mary\Traits\Toast;

#[Layout('components.layouts.app')]
#[Title('ایجاد زون جدید')]
class Create extends Component
{
    use Toast;

    #[Rule('required|string|max:255|unique:zones,name')]
    public string $name = '';

    #[Rule('nullable|string')]
    public string $description = '';

    #[Rule('required|string|size:7|regex:/^#[0-9A-Fa-f]{6}$/')]
    public string $color = '#3B82F6';

    #[Rule('boolean')]
    public bool $is_active = true;

    public array $selectedUnits = [];
    public array $availableUnits = [];

    protected $messages = [
        'name.required' => 'نام زون الزامی است.',
        'name.unique' => 'این نام زون قبلاً ثبت شده است.',
        'color.required' => 'رنگ الزامی است.',
        'color.regex' => 'فرمت رنگ باید هگزادسیمال باشد (مثال: #3B82F6).',
    ];

    public function mount(): void
    {
        $this->loadAvailableUnits();
    }

    public function loadAvailableUnits(): void
    {
        $accessibleUnitIds = app(AccessService::class)->accessibleUnitIds();
        $this->availableUnits = Unit::whereIn('id', $accessibleUnitIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function save(): \Illuminate\Http\RedirectResponse
    {
        $this->validate();

        $zone = Zone::create([
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
        ]);

        if (!empty($this->selectedUnits)) {
            $zone->units()->attach(
                array_combine($this->selectedUnits, array_fill(0, count($this->selectedUnits), ['assigned_at' => now()]))
            );
        }

        $this->success('زونا با موفقیت ایجاد شد.');
        return redirect()->route('zones.index');
    }

    public function cancel(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('zones.index');
    }

    public function render()
    {
        return view('livewire.zones.create');
    }
}