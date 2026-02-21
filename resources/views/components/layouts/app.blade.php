<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.draw.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet-routing-machine.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/other/jalalidatepicker.min.css') }}" />


    <script src="{{ asset('js/chart/highcharts.js') }}" defer></script>
    <script src="{{ asset('js/chart/treemap.js') }}" defer></script>
    <script src="{{ asset('js/chart/treegraph.js') }}" defer></script>
    <script src="{{ asset('js/chart/exporting.js') }}" defer></script>
    <script src="{{ asset('js/chart/accessibility.js') }}" defer></script>

    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet.draw.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet.geometryutil.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
<style>
    @font-face {
        font-family: 'Vazirmatn';
        src: url('/fonts/Vazirmatn-Regular.woff2') format('woff2');
        font-weight: normal;
    }

    body {
        font-family: 'Vazirmatn', sans-serif !important;
        direction: rtl;
    }
    
    /* برای اینکه کلاس‌های پیش‌فرض تیلوند هم از این فونت استفاده کنند */
    .font-sans {
        font-family: 'Vazirmatn', sans-serif !important;
    }
</style>
</head>

<body class="min-h-screen font-sans antialiased bg-base-200">
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    <x-main>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible collapse-text="بستن منو" class="bg-base-100 lg:bg-inherit 2xl:collapse ">

            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            <x-menu activate-by-route>
                @if($user = auth()->user())
                <x-menu-separator />
                <x-list-item :item="auth()->user()" value="name" no-separator no-hover
                    class="-mx-2 !-my-2 rounded">
                    <x-slot:actions>
                        <x-button icon="o-cog" class="btn-circle btn-ghost btn-xs" tooltip-right="changepassword"
                            no-wire-navigate link="/users/changepassword" />
                        <x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-right="logoff"
                            no-wire-navigate link="/logout" />
                    </x-slot:actions>
                </x-list-item>
                <x-menu-separator />
                @endif




                <x-menu-item title="صفحه اول" icon="o-sparkles" link="/" wire:navigate />
                <x-menu-sub title="مدیریت" icon="o-cog-6-tooth">
                    <x-menu-item title="کاربران" icon="o-sparkles" link="/users" wire:navigate />

                    <x-menu-sub title="منابع انسانی" icon="o-cog-6-tooth">
                        <x-menu-item title="استخدام" icon="o-sparkles" link="/kargozini/estekhdams" wire:navigate />
                        <x-menu-item title="ردیف سازمانی" icon="o-sparkles" link="/kargozini/radifs" wire:navigate />
                        <x-menu-item title="تحصیلات" icon="o-sparkles" link="/kargozini/tahsils" wire:navigate />
                        <x-menu-item title="سمت‌ها" icon="o-sparkles" link="/kargozini/semats" wire:navigate />
                        <x-menu-item title="پرسنل" icon="o-sparkles" link="/kargozini/persons" wire:navigate />
                    </x-menu-sub>
                    <x-menu-sub title="مدیریت سطح دسترسی" icon="o-cog-6-tooth">
                        <x-menu-item title="مدیریت دسترسی ها" icon="o-users" link="/permissions" wire:navigate />
                        <x-menu-item title="مدیریت نقش ها" icon="o-users" link="/roles" wire:navigate />
                    </x-menu-sub>
                     <x-menu-sub title="مدیریت تیکت ها" icon="o-cog-6-tooth">
                        <x-menu-item title="  ایجاد تیکت " icon="o-users" link="/tickets/new" wire:navigate />
                        <x-menu-item title="صندوق تیکت ها  " icon="o-users" link="/tickets/inbox" wire:navigate />
                       <x-menu-item title="مانیتورینگ کل تیکت ها  " icon="o-users" link="/monitoring" wire:navigate />

                    </x-menu-sub>
                    <x-menu-sub title="ساختار سازمان" icon="o-cog-6-tooth">
                        <x-menu-item title="مدیریت واحدها" icon="o-sparkles" link="/units" wire:navigate />
                        <x-menu-item title=" درختواره واحدها " icon="o-document-text" link="/units/chart" wire:navigate />
                    </x-menu-sub>


                    @can('map')
                    <x-menu-sub title="کار با نقشه" icon="o-cog-6-tooth">
                        <x-menu-item title="مسیر" icon="o-sparkles" link="/maps/route" wire:navigate />
                        <x-menu-item title="یافتن مسیر" icon="o-sparkles" link="/maps/route2" wire:navigate />
                        <x-menu-item title="رسم شکل" icon="o-sparkles" link="/maps/draw" wire:navigate />
                        <x-menu-item title="شهرستان‌ها" icon="o-sparkles" link="/maps/county" wire:navigate />
                        <x-menu-item title="نقشه واحدها" icon="o-sparkles" link="/maps/unit" wire:navigate />
                        <x-menu-item title="موقعیت کاربر" icon="o-sparkles" link="/maps/location" wire:navigate />
                        <x-menu-item title="نقشه نقاط" icon="o-sparkles" link="/maps/point" wire:navigate />
                    </x-menu-sub>
                    @endcan

                    @can('op-cache')
                    <x-menu-sub title=" ابزارهای مدیریتی " icon="o-cog-6-tooth">
                        <x-menu-item title="OPcache" icon="o-sparkles" link="/op" target="_blank" no-wire-navigate rel="noopener noreferrer" />
                    </x-menu-sub>
                    @endcan
                </x-menu-sub>

            </x-menu>
        </x-slot:sidebar>
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    <x-toast />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('livewire:init', () => {
       Livewire.on('swal', (event) => {
           const data = event[0]; // در لاووایر ۳ داده‌ها در اولین ایندکس آرایه هستند
           Swal.fire({
               title: data.title,
               icon: data.icon,
               confirmButtonText: 'تایید',
               timer: 3000,
               toast: true,
               position: 'top-end'
           });
       });
    });
</script>

</body>

</html>