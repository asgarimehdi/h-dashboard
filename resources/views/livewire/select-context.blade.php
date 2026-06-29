<?php

use App\Services\AccessService;
use Livewire\Component;

return new class extends Component {
    public ?int $selectedUnitId = null;

    public function mount()
    {
        $userUnits = auth()->user()->units;

        if ($userUnits->isEmpty()) {
            session(['current_unit_id' => auth()->user()->person?->u_id]);
            session(['current_unit_name' => auth()->user()->person?->unit?->name ?? '-']);

            return redirect('/dashboard');
        }

        if ($userUnits->count() === 1) {
            $unit = $userUnits->first();
            session(['current_unit_id' => $unit->id]);
            session(['current_unit_name' => $unit->name]);

            return redirect('/dashboard');
        }
    }

    public function selectContext(): void
    {
        $user = auth()->user();

        if (! $this->selectedUnitId) {
            return;
        }

        $hasAccess = $user->units()->where('units.id', $this->selectedUnitId)->exists();

        if (! $hasAccess) {
            return;
        }

        $unit = \App\Models\Unit::find($this->selectedUnitId);

        session(['current_unit_id' => $unit->id]);
        session(['current_unit_name' => $unit->name]);

        app(AccessService::class)->clearCache($user);

        $this->redirect('/dashboard');
    }

    public function with(): array
    {
        return [
            'userUnits' => auth()->user()->units,
        ];
    }
};
?>

<div class="flex items-center justify-center min-h-[60vh]">
    <x-card shadow class="max-w-md w-full">
        <x-header title="انتخاب حوزه فعالیت" subtitle="لطفاً واحد سازمانی مورد نظر خود را انتخاب کنید" separator />

        <x-form wire:submit="selectContext">
            <div class="space-y-3">
                @foreach($userUnits as $unit)
                    <label
                        class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition
                            {{ $selectedUnitId === $unit->id ? 'border-primary bg-primary/10' : 'border-base-300 hover:border-primary/50' }}"
                        wire:click="$set('selectedUnitId', {{ $unit->id }})"
                    >
                        <input type="radio" name="unit" value="{{ $unit->id }}" wire:model="selectedUnitId"
                            class="radio radio-primary" />
                        <div>
                            <div class="font-bold">{{ $unit->name }}</div>
                            @if($unit->unitType)
                                <div class="text-sm opacity-60">{{ $unit->unitType->name }}</div>
                            @endif
                            <div class="text-xs opacity-40">
                                {{ $unit->pivot->role === 'responsible' ? 'مسئول' : 'کارشناس' }}
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            <x-slot:actions>
                <x-button type="submit" label="ورود" icon="o-arrow-left-start-on-rectangle" class="btn-primary"
                    :disabled="!$selectedUnitId" spinner />
            </x-slot:actions>
        </x-form>
    </x-card>
</div>
