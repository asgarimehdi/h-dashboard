<?php

use App\Services\AccessService;
use Livewire\Component;

return new class extends Component
{
    public ?int $selectedUnitId = null;

    public bool $showHelpModal = false;

    public function mount()
    {
        $userUnits = auth()->user()->units;

        // Membership (`user_units`) is the sole source of scope (#1830, owner
        // decision D1=B). A memberless account gets NO context here: falling
        // back to the person's HR `u_id` would resurrect a revoked unit in
        // the session and bounce the browser between this page and
        // `/dashboard` forever.
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
        <x-header title="انتخاب حوزه فعالیت"
            subtitle="{{ $userUnits->isEmpty() ? 'حساب شما به هیچ واحد سازمانی متصل نیست' : 'لطفاً واحد سازمانی مورد نظر خود را انتخاب کنید' }}"
            separator>
            <x-slot:actions>
                <x-help:button section="select-context" wireModel="showHelpModal" />
            </x-slot:actions>
        </x-header>

        @if($userUnits->isEmpty())
            <div class="alert alert-info" role="alert">
                <x-icon name="o-information-circle" class="w-5 h-5" />
                <span>حساب شما به هیچ واحد سازمانی تخصیص نیافته است؛ برای دسترسی با مدیر سامانه تماس بگیرید.</span>
            </div>
        @else
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
        @endif
    </x-card>

    <x-help:modal wireModel="showHelpModal" section="select-context" />
</div>
