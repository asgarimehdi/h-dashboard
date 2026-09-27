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

@script
<script>
    // یک درخواست برای همهٔ گیج‌های صفحه، به‌جای یک درخواست به‌ازای هر گیج.
    //
    // چرا: مسیر `/api/zabbix/multi-latest` زیر `throttle:api-user` است
    // (۶۰ درخواست در دقیقه برای کل کاربر، نه برای هر صفحه). با ۱۴ دستگاه،
    // حلقهٔ ۳۰ ثانیه‌ایِ هر گیج ۲۸ درخواست در دقیقه می‌ساخت و صفحه بعد از چند
    // ثانیه با ۴۲۹ «Too Many Attempts» می‌افتاد. اکنون هر چرخه = یک درخواست.
    (() => {
        const state = window.zabbixGauges || (window.zabbixGauges = {
            values: {}, error: null, loading: false, loaded: false, subscribers: [], timer: null,
        });

        const itemIds = @js($this->itemIds());
        const REFRESH_MS = 30000;
        // سقف اعتبارسنجی endpoint = ۱۰۰ آیتم در هر درخواست.
        const CHUNK = 90;

        if (state.timer) {
            clearInterval(state.timer);
            state.timer = null;
        }
        state.values = {};
        state.error = null;
        state.loaded = false;

        const publish = () => {
            state.subscribers.slice().forEach((subscriber) => subscriber());
        };

        const load = async () => {
            if (!itemIds.length || state.loading) return;

            state.loading = true;
            publish();

            try {
                const token = localStorage.getItem('token');
                const headers = token ? { 'Authorization': `Bearer ${token}` } : {};

                const values = {};

                for (let offset = 0; offset < itemIds.length; offset += CHUNK) {
                    const params = new URLSearchParams();
                    itemIds
                        .slice(offset, offset + CHUNK)
                        .forEach((id, index) => params.append(`item_ids[${index}]`, id));

                    const response = await fetch(`/api/zabbix/multi-latest?${params.toString()}`, { headers });

                    if (!response.ok) {
                        // #703: a 503 from our API means Zabbix itself is
                        // unreachable. That is NOT an internal error — it is a
                        // server we cannot talk to, so show the requested
                        // friendly message instead of "خطای HTTP 503".
                        if (response.status === 503) {
                            state.error = 'دسترسی به سرور مقدور نمی باشد';
                            return;
                        }

                        let errorMsg = `خطای HTTP ${response.status}`;
                        try {
                            const text = await response.text();
                            try {
                                const errorData = JSON.parse(text);
                                if (errorData.message) errorMsg = errorData.message;
                            } catch {
                                errorMsg = text.substring(0, 100);
                            }
                        } catch (e) {}
                        state.error = errorMsg;
                        return;
                    }

                    Object.assign(values, await response.json());
                }

                state.values = values;
                state.error = null;
            } catch (e) {
                console.error('Error fetching values:', e);

                // #703: a TypeError from fetch() means the request never
                // reached the server (network down / server offline), which
                // is the same "cannot reach the server" condition as a 503.
                state.error = e instanceof TypeError
                    ? 'دسترسی به سرور مقدور نمی باشد'
                    : (e.message || 'خطا در دریافت');
            } finally {
                state.loading = false;
                state.loaded = true;
                publish();
            }
        };

        load();
        state.timer = setInterval(load, REFRESH_MS);
    })();
</script>
@endscript

