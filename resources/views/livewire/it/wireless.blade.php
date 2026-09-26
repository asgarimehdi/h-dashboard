<?php

use App\Models\ZabbixDevice;
use App\Services\CacheInvalidationServiceInterface;
use Livewire\Component;

return new class extends Component {
    /**
     * نمایش دستگاه‌های بی‌سیم — از جدول `zabbix_devices` خوانده می‌شود (Issue #698).
     * شکل آرایه مثل قبل است تا حلقه @foreach بدون تغییر بماند.
     *
     * @var array<int, array{signalId: string, freqId: string, respId: string, name: string, min: float, max: float}>
     */
    public array $signalItems = [];

    public bool $showHelpModal = false;

    public function mount(): void
    {
        $this->signalItems = $this->loadSignalItems();
    }

    /**
     * @return array<int, array{signalId: string, freqId: string, respId: string, name: string, min: float, max: float}>
     */
    protected function loadSignalItems(): array
    {
        $cache = app(CacheInvalidationServiceInterface::class);

        /** @var array<int, array{signalId: string, freqId: string, respId: string, name: string, min: float, max: float}> $items */
        $items = $cache->remember(
            ZabbixDevice::CACHE_NAMESPACE,
            'wireless',
            fn () => ZabbixDevice::query()
                ->active()
                ->ofType(ZabbixDevice::TYPE_WIRELESS)
                ->ordered()
                ->get()
                ->map(fn (ZabbixDevice $device) => [
                    'signalId' => (string) $device->signal_item_id,
                    'freqId' => (string) $device->frequency_item_id,
                    'respId' => (string) $device->response_item_id,
                    'name' => $device->name,
                    'min' => (float) ($device->min ?? -85),
                    'max' => (float) ($device->max ?? -45),
                ])
                ->values()
                ->all(),
            5
        );

        return $items;
    }
};
?>

<div>
    <!-- HEADER -->
    <x-header title="دستگاه های بی سیم" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-help:button section="wireless" wireModel="showHelpModal" />
            <x-theme-selector/>
        </x-slot:actions>
    </x-header>

    <x-help:modal wireModel="showHelpModal" />

    <!-- TABLE  -->
    <x-card shadow>
        <div class="flex gap-2 items-center mb-4">
            <div class="flex-1">
            </div>
        </div>
        
        <div class="p-6">
            {{-- بخش گیج‌های سیگنال با Lazy Loading --}}
            @if(count($this->signalItems) === 0)
                <p class="text-sm opacity-70">دستگاهی برای نمایش ثبت نشده است.</p>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 mt-4">
                @foreach($this->signalItems as $item)
                    @php
                        // ایجاد key یکتا برای هر کامپوننت
                        $key = 'gauge-' . $item['signalId'] . '-' . $item['freqId'] . '-' . $item['respId'];
                    @endphp
                    
                    {{-- استفاده از lazy با کلید یکتا --}}
                    <livewire:it.multi-gauge 
                        :signal-item-id="$item['signalId']"
                        :frequency-item-id="$item['freqId']"
                        :response-time-item-id="$item['respId']"
                        :title="$item['name']"
                        :min="$item['min']"
                        :max="$item['max']"
                        unit="dBm"
                        frequency-unit="MHz"
                        response-time-unit="ms"
                        :key="$key"
                        lazy
                    />
                @endforeach
            </div>
        </div>
    </x-card>
</div>
