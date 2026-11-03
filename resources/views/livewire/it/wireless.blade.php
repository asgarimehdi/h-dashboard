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
     * شناسهٔ همهٔ آیتم‌هایی که گیج‌ها لازم دارند، بدون تکرار.
     *
     * یک درخواست `/api/zabbix/multi-latest` تا ۱۰۰ آیتم می‌پذیرد، پس کل صفحه با
     * یک بار فراخوانی همهٔ گیج‌ها را پر می‌کند — به‌جای یک درخواست به‌ازای هر گیج.
     *
     * @return array<int, string>
     */
    public function itemIds(): array
    {
        return array_values(array_unique(array_merge(
            [],
            ...array_map(
                fn (array $item) => [$item['signalId'], $item['freqId'], $item['respId']],
                $this->signalItems
            )
        )));
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
            {{-- بخش گیج‌های سیگنال — یک poller مشترک برای همهٔ گیج‌ها --}}
            @if(count($this->signalItems) === 0)
                <p class="text-sm opacity-70">دستگاهی برای نمایش ثبت نشده است.</p>
            @endif
            {{-- Inert data for the shared poller in zabbix-gauges.js.
                 Must sit INSIDE the root element: Livewire drops trailing
                 content after the root close tag (chunk-5 lesson). --}}
            <script type="application/json" nonce="{{ Vite::cspNonce() }}" id="wireless-item-ids">@json($this->itemIds())</script>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 mt-4">
                @foreach($this->signalItems as $item)
                    @php
                        // ایجاد key یکتا برای هر کامپوننت
                        $key = 'gauge-' . $item['signalId'] . '-' . $item['freqId'] . '-' . $item['respId'];
                    @endphp

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
                    />
                @endforeach
            </div>
        </div>
    </x-card>
</div>


