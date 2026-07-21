<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.draw.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet-routing-machine.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/other/jalalidatepicker.min.css') }}" />


    <script src="{{ asset('js/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet.draw.js') }}"></script>
    <script src="{{ asset('js/leaflet/leaflet-routing-machine.min.js') }}"></script>
    <script src="{{ asset('js/other/jalalidatepicker.min.js') }}"></script>
    <script src="{{ asset('js/other/full-calendar.min.js') }}"></script>
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
            <livewire:notifications.bell />
            <a href="/search" wire:navigate class="btn btn-ghost btn-sm">
                <x-icon name="o-magnifying-glass" class="w-5 h-5" />
                <span class="hidden md:inline text-xs">جستجو</span>
            </a>
            <a href="/profile" wire:navigate class="btn btn-ghost btn-sm gap-2">
                <x-icon name="o-user-circle" class="w-5 h-5" />
                <span class="hidden md:inline text-xs">{{ Auth::user()->person?->f_name ?? 'کاربر' }}</span>
            </a>
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    <x-main>
        <!-- Mobile-only Search & Notifications row -->
        <div class="lg:hidden flex items-center gap-3 p-3 border-b border-base-200">
            <a href="/search" wire:navigate class="btn btn-ghost btn-sm flex-1 justify-start">
                <x-icon name="o-magnifying-glass" class="w-5 h-5" />
                <span class="text-sm">جستجو</span>
            </a>
            <livewire:notifications.bell />
        </div>

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
                        <x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-right="logoff"
                            no-wire-navigate link="/logout" />
                    </x-slot:actions>
                </x-list-item>
                <x-menu-separator />
                @endif

                {{-- Context Selector: نمایش واحد فعلی و امکان تغییر --}}
                @if(session('current_unit_name'))
                <div class="px-4 py-2">
                    <div class="text-xs opacity-50 mb-1">حوزه فعالیت:</div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-bold truncate">{{ session('current_unit_name') }}</span>
                        @if(auth()->user()->units()->count() > 1)
                            <x-button icon="o-arrows-right-left" class="btn-ghost btn-xs"
                                tooltip-right="تغییر حوزه" no-wire-navigate link="/select-context" />
                        @endif
                    </div>
                </div>
                <x-menu-separator />
                @endif
                <x-menu-item title="جستجوی کلی" icon="o-magnifying-glass" link="/search" wire:navigate />
                <x-menu-item title="صفحه اول" icon="o-home" link="/" wire:navigate />

                {{-- منابع انسانی --}}
                @can('kargozini')
                <x-menu-sub title="منابع انسانی" icon="o-user-group">
                    <x-menu-item title="پرسنل" icon="o-user-group" link="/kargozini/persons" wire:navigate />
                    <x-menu-item title="استخدام" icon="o-briefcase" link="/kargozini/estekhdams" wire:navigate />
                    <x-menu-item title="ردیف سازمانی" icon="o-bars-3-bottom-right" link="/kargozini/radifs" wire:navigate />
                    <x-menu-item title="تحصیلات" icon="o-academic-cap" link="/kargozini/tahsils" wire:navigate />
                    <x-menu-item title="سمت‌ها" icon="o-clipboard-document-list" link="/kargozini/semats" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- مدیریت تیکت‌ها --}}
                @canany(['create_ticket', 'view_assigned_tickets', 'view_all_tickets'])
                <x-menu-sub title="مدیریت تیکت‌ها" icon="o-ticket">
                    @can('create_ticket')
                    <x-menu-item title="تیکت جدید" icon="o-plus-circle" link="/tickets/new" wire:navigate />
                    @endcan
                    @can('view_assigned_tickets')
                    <x-menu-item title="صندوق تیکت‌ها" icon="o-inbox" link="/tickets/inbox" wire:navigate />
                    @endcan
                    @can('view_all_tickets')
                    <x-menu-item title="مانیتورینگ" icon="o-chart-bar" link="/monitoring" wire:navigate />
                    @endcan
                    @can('calendar')
                    <x-menu-item title="تقویم" icon="o-calendar-days" link="/todo" wire:navigate />
                    @endcan
                </x-menu-sub>
                @endcanany

                {{-- ساختار سازمان --}}
                @can('organization')
                <x-menu-sub title="ساختار سازمان" icon="o-building-library">
                    <x-menu-item title="مدیریت واحدها" icon="o-building-office-2" link="/units" wire:navigate />
                    <x-menu-item title="درختواره واحدها" icon="o-folder" link="/units/chart" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- کار با نقشه --}}
                @can('map')
                <x-menu-sub title="کار با نقشه" icon="o-map">
                    <x-menu-item title="نقشه واحدها" icon="o-building-library" link="/maps/unit" wire:navigate />
                    <x-menu-item title="مسیر" icon="o-map" link="/maps/route" wire:navigate />
                    <x-menu-item title="یافتن مسیر" icon="o-magnifying-glass-circle" link="/maps/route2" wire:navigate />
                    <x-menu-item title="شهرستان‌ها" icon="o-map-pin" link="/maps/county" wire:navigate />
                    <x-menu-item title="نقشه نقاط" icon="o-map" link="/maps/point" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- ابزارهای مدیریتی --}}
                @can('bw')
                <x-menu-sub title="ابزارهای مدیریتی" icon="o-wrench-screwdriver">
                    <x-menu-item title="شبکه‌ها" icon="o-globe-alt" link="/it/networks" wire:navigate />
                    <x-menu-item title="وایرلس‌ها" icon="o-signal" link="/it/wireless" wire:navigate />
                    <a href="/op" class="flex items-center gap-3 px-4 py-2 text-sm rounded-lg hover:bg-base-200 transition-colors">
                        <x-icon name="o-server" class="w-5 h-5" />
                        <span>کش سرور</span>
                    </a>
                    <x-menu-item title="ابزارها" icon="o-wrench" link="/tools" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- گزارش‌ها --}}
                <x-menu-sub title="گزارش‌ها" icon="o-chart-bar">
                    <x-menu-item title="گزارش واحدها و مراکز" icon="o-building-office" link="/reports/units" wire:navigate />
                    <x-menu-item title="گزارش پرسنل" icon="o-users" link="/reports/persons" wire:navigate />
                    <x-menu-item title="گزارش وظایف" icon="o-check-circle" link="/reports/todos" wire:navigate />
                    <x-menu-item title="نقاط فاقد مرز" icon="o-no-symbol" link="/reports/map-no-boundary" wire:navigate />
                    <x-menu-item title="گزارش تیکت‌ها" icon="o-ticket" link="/reports" wire:navigate />
                    <x-menu-item title="گزارش پیشرفته" icon="o-adjustments-vertical" link="/reports/advanced" wire:navigate />
                    @can('manage_users')
                    <x-menu-item title="گزارش فعالیت" icon="o-clock" link="/activity-log" wire:navigate />
                    @endcan
                </x-menu-sub>

                {{-- مدیریت سیستم --}}
                @canany(['manage_users', 'manage_roles'])
                <x-menu-sub title="مدیریت" icon="o-cog-6-tooth">
                    @can('manage_users')
                    <x-menu-item title="کاربران" icon="o-users" link="/users" wire:navigate />
                    @endcan
                    @can('manage_roles')
                    <x-menu-item title="مدیریت نقش‌ها" icon="o-shield-check" link="/roles" wire:navigate />
                    <x-menu-item title="مدیریت دسترسی‌ها" icon="o-lock-closed" link="/permissions" wire:navigate />
                    @endcan
                </x-menu-sub>
                @endcanany

                <x-menu-separator />

                {{-- حساب کاربری --}}
                <x-menu-item title="پروفایل من" icon="o-user-circle" link="/profile" wire:navigate />
                <x-menu-item title="تغییر رمز عبور" icon="o-lock-closed" link="/users/changepassword" wire:navigate />
                <x-menu-item title="تنظیمات" icon="o-cog-6-tooth" link="/settings" wire:navigate />
            </x-menu>
        </x-slot:sidebar>
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    <x-toast />
    <!-- <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> -->
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

@stack('scripts')

</body>

</html>