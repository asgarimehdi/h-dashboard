<?php

use App\Models\ZabbixDevice;
use App\Services\CacheInvalidationServiceInterface;
use Livewire\Component;

return new class extends Component {
    /**
     * نمایش دستگاه‌های شبکه — از جدول `zabbix_devices` خوانده می‌شود (Issue #698).
     * شکل آرایه مثل قبل است تا حلقه @foreach بدون تغییر بماند.
     *
     * @var array<int, array{out-item-id: string, in-item-id: string, title: string, initial-duration: string}>
     */
    public array $networkItems = [];

    public bool $showHelpModal = false;

    public function mount(): void
    {
        $this->networkItems = $this->loadNetworkItems();
    }

    /**
     * @return array<int, array{out-item-id: string, in-item-id: string, title: string, initial-duration: string}>
     */
    protected function loadNetworkItems(): array
    {
        $cache = app(CacheInvalidationServiceInterface::class);

        /** @var array<int, array{out-item-id: string, in-item-id: string, title: string, initial-duration: string}> $items */
        $items = $cache->remember(
            ZabbixDevice::CACHE_NAMESPACE,
            'networks',
            fn () => ZabbixDevice::query()
                ->active()
                ->ofType(ZabbixDevice::TYPE_NETWORK)
                ->ordered()
                ->get()
                ->map(fn (ZabbixDevice $device) => [
                    'out-item-id' => (string) $device->out_item_id,
                    'in-item-id' => (string) $device->in_item_id,
                    'title' => $device->name,
                    'initial-duration' => (string) $device->initial_duration,
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
    <x-header title="داشبورد فناوری اطلاعات" separator progress-indicator>
        <x-slot:middle class="!justify-end">
        </x-slot:middle>
        <x-slot:actions>
            <x-help:button section="networks" wireModel="showHelpModal" />
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
            <h1 class="text-3xl font-bold mb-4">ترافیک شبکه</h1>
            @island(lazy:true)
            @if(count($this->networkItems) === 0)
                <p class="text-sm opacity-70">دستگاهی برای نمایش ثبت نشده است.</p>
            @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($this->networkItems as $network)
                    <div class="flex-1">
                        <livewire:it.network-traffic-chart 
                            :out-item-id="$network['out-item-id']" 
                            :in-item-id="$network['in-item-id']"  
                            :title="$network['title']"  
                            :initial-duration="$network['initial-duration']"  
                        />
                    </div>
                @endforeach
            </div>
            @endisland
        </div>
    </x-card>
</div>
