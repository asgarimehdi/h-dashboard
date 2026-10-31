<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    <link rel="preload" href="{{ asset('fonts/Vazirmatn-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Issue #984 (CSS half, #1018): page-specific stylesheets land in
         `@stack('styles')` right here — the draw and routing stylesheets used to
         be linked globally below (~11.5 KB of parser-blocking CSS in the hot
         path for three pages that use them). Their pages push them now. --}}
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/other/jalalidatepicker.min.css') }}" />


    {{-- Issues #984 / #1018 / #1182: Leaflet, jalalidatepicker, leaflet.draw,
         leaflet-routing-machine and FullCalendar are page-specific. Loading them
         here cost ~914 KB of render-blocking JS for the three plugin/calendar
         libraries (~85% of the global JS payload) on top of ~167 KB for Leaflet
         core + jalalidatepicker — on every page, including the ones that never
         touch a map or a Persian date field. Each page now loads what it needs
         with `@assets` (also from #1182 — see below):
           maps/route, maps/route2  -> leaflet core + routing-machine
           units/map                -> leaflet core + leaflet.draw
           map/map-dashboard, maps/county, maps/unit, maps/point,
           reports/map-no-boundary  -> leaflet core        (8 Leaflet pages total)
           activity-log/index, reports/advanced, reports/todos,
           tickets/inbox, tickets/monitoring, todo/todo, map/map-dashboard
                                    -> jalalidatepicker   (7 date pages total)
         Two rules for whoever moves the next library:
         1. It goes on the HOST page, never inside `maps/map.blade.php` — the
            child's asset arrives with the child, after the page's Alpine
            `init()` already asked the store for a map.
         2. It goes through `@assets`, NOT `@push('scripts')`. Livewire injects
            `@assets` into this <head> and evaluates new head assets before it
            completes a `wire:navigate` swap. A pushed tag lands at the end of
            <body>, and on navigate Livewire morphs the body and runs Alpine
            BEFORE evaluating those scripts — measured as `L is not defined` and
            a blank map on /dashboard -> /maps/county (#028, #1182).
         The CSS half (#1018) is untouched by #1182: `leaflet.css` and
         `jalalidatepicker.min.css` stay linked here, and LeafletCssScopingTest
         pins them to this <head>.
         Do not move a library back here without re-measuring. --}}
@php
    $compactMode = auth()->user()->settings['compact_mode'] ?? false;
@endphp
</head>

<body class="min-h-screen font-sans antialiased stitch-bg {{ $compactMode ? 'compact-mode' : '' }}">
    {{-- #957: لینک پرش. `focus:absolute` لازم است چون `not-sr-only` حالتِ
         static می‌گذارد و بدون آن با هر بار فوکوس، کل صفحه یک خط پایین می‌رود. --}}
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-[1000] focus:rounded-lg focus:bg-base-100 focus:px-4 focus:py-2 focus:shadow-lg">
        رفتن به محتوای اصلی
    </a>
    <!-- Stitch-style animated background JavaScript -->
    <script nonce="{{ Vite::cspNonce() }}">
        // Initialize theme from localStorage on load (runs before Alpine/Livewire)
        (function() {
            const savedTheme = localStorage.getItem('mary-theme')?.replaceAll('"', '');
            const savedClass = localStorage.getItem('mary-class')?.replaceAll('"', '');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
            if (savedClass) {
                document.documentElement.setAttribute('class', savedClass);
            }
        })();

        // Create floating gradient blobs
        function createBlobs() {
            const container = document.querySelector('.stitch-blobs');
            if (!container || container.children.length > 0) return; // Already created

            const blobConfigs = [
                { class: 'blob-1', size: Math.random() * 200 + 300, top: '10%', left: '5%' },
                { class: 'blob-2', size: Math.random() * 150 + 250, top: '60%', right: '10%' },
                { class: 'blob-3', size: Math.random() * 180 + 280, bottom: '20%', left: '50%' }
            ];

            blobConfigs.forEach(config => {
                const blob = document.createElement('div');
                blob.className = `stitch-blob ${config.class}`;
                blob.style.width = `${config.size}px`;
                blob.style.height = `${config.size}px`;

                if (config.top) blob.style.top = config.top;
                if (config.bottom) blob.style.bottom = config.bottom;
                if (config.left) blob.style.left = config.left;
                if (config.right) blob.style.right = config.right;

                container.appendChild(blob);
            });
        }

        // Update blob colors when theme changes
        function updateBlobsForTheme(theme) {
            const blobs = document.querySelectorAll('.stitch-blob');
            blobs.forEach(blob => {
                // Force reflow to trigger CSS theme-based color change
                blob.style.display = 'none';
                blob.offsetHeight; // trigger reflow
                blob.style.display = '';
            });
        }

        // Create blobs when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', createBlobs);
        } else {
            createBlobs();
        }

        // Listen for theme changes from MaryUI theme toggle
        window.addEventListener('theme-changed', (e) => {
            updateBlobsForTheme(e.detail);
        });

        // Also listen for the mary-theme localStorage changes (for multi-tab sync)
        window.addEventListener('storage', (e) => {
            if (e.key === 'mary-theme' || e.key === 'mary-class') {
                setTimeout(createBlobs, 100);
            }
        });

        // Re-create blobs on Livewire navigate (since DOM may be partially replaced)
        document.addEventListener('livewire:navigated', () => {
            setTimeout(() => {
                const container = document.querySelector('.stitch-blobs');
                if (container && container.children.length === 0) {
                    createBlobs();
                }
            }, 100);
        });
    </script>

    <!-- Animated floating blobs (stitched background) -->
    <div class="stitch-blobs fixed inset-0 pointer-events-none -z-10" aria-hidden="true"></div>

    <!-- Stitch-style woven grid background -->
    <div class="stitch-bg-fixed fixed inset-0 -z-20 pointer-events-none" aria-hidden="true"></div>
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <a href="/search" wire:navigate aria-label="جستجو" class="btn btn-ghost btn-sm">
                <x-icon name="o-magnifying-glass" class="w-5 h-5" />
                <span class="hidden md:inline text-xs">جستجو</span>
            </a>
            <a href="/profile" wire:navigate aria-label="پروفایل {{ Auth::user()->person?->f_name ?? 'کاربر' }}" class="btn btn-ghost btn-sm gap-2">
                <x-icon name="o-user-circle" class="w-5 h-5" />
                <span class="hidden md:inline text-xs">{{ Auth::user()->person?->f_name ?? 'کاربر' }}</span>
            </a>
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    <x-main full-width>
        <!-- Mobile-only Search & Notifications row -->
        <div class="lg:hidden flex items-center gap-3 p-3 border-b border-base-200">
            <a href="/search" wire:navigate class="btn btn-ghost btn-sm flex-1 justify-start">
                <x-icon name="o-magnifying-glass" class="w-5 h-5" />
                <span class="text-sm">جستجو</span>
            </a>
        </div>

        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible collapse-text="بستن منو" class="bg-base-100 lg:bg-inherit 2xl:collapse ">

            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            {{-- #957: برچسب‌دار کردن نشانهٔ ناوبری. بدون `aria-label` چند
                 ناوبری روی صفحه برای صفحه‌خوان یکسان و بی‌نام می‌شوند. --}}
            <nav aria-label="منوی اصلی">
                {{-- #1220: جفت‌رنگ آیتم فعال باید از یک خانوادهٔ توکن بیاید.
                     daisyUI برای `[aria-current="page"]` متن را با
                     `--menu-active-fg` (= `--color-neutral-content`) رنگ می‌کند،
                     ولی MaryUI پس‌زمینه را از `bg-base-300` می‌گیرد؛ در تم‌های
                     روشن این دو به هم می‌رسند (light 1.09، cupcake/fantasy 1.01).
                     تصمیم مالک: جفت بومی daisyUI (`neutral` / `neutral-content`)
                     در هر ۷ تم — پس هر دو نیمه از یک خانواده‌اند.
                     `activeBgColor` هم به آیتم فعال و هم به `<summary>` زیرمنوی
                     باز cascade می‌شود؛ اعداد در tests/e2e/theme پین شده‌اند. --}}
                <x-menu activate-by-route active-bg-color="bg-neutral text-neutral-content">
                @if($user = auth()->user())
                <x-menu-separator />
                <x-list-item :item="auth()->user()" value="name" no-separator no-hover
                    class="-mx-2 !-my-2 rounded">
                    <x-slot:actions>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-ui.icon-button name="خروج از حساب" type="submit" icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-right="logoff" no-wire-navigate />
                        </form>
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
                            <x-ui.icon-button name="تغییر حوزه" icon="o-arrows-right-left" class="btn-ghost btn-xs"
                                tooltip-right="تغییر حوزه" no-wire-navigate link="/select-context" />
                        @endif
                    </div>
                </div>
                <x-menu-separator />
                @endif
                <x-menu-item title="جستجوی کلی" icon="o-magnifying-glass" link="/search" wire:navigate />
                {{-- #996: روت `/` یک `Route::redirect('/', '/dashboard')` است؛
                     با `link="/"` این آیتم هیچوقت active نمی‌شد. --}}
                <x-menu-item title="صفحه اول" icon="o-home" link="/dashboard" wire:navigate />

                {{-- منابع انسانی --}}
                @can('kargozini')
                <x-menu-sub title="منابع انسانی" icon="o-user-group">
                    <x-menu-item title="استخدام" icon="o-briefcase" link="/kargozini/estekhdams" wire:navigate />
                    <x-menu-item title="ردیف سازمانی" icon="o-bars-3-bottom-right" link="/kargozini/radifs" wire:navigate />
                    <x-menu-item title="تحصیلات" icon="o-academic-cap" link="/kargozini/tahsils" wire:navigate />
                    <x-menu-item title="سمت‌ها" icon="o-clipboard-document-list" link="/kargozini/semats" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- مدیریت تیکت‌ها --}}
                @canany(['create_ticket', 'view_assigned_tickets', 'view_all_tickets', 'calendar'])
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

                {{-- مدیریت سازمان --}}
                @canany(['organization', 'kargozini', 'view_hr_dashboard', 'manage_org_chart', 'manage_personnel'])
                <x-menu-sub title="مدیریت سازمان" icon="o-building-library">
                    @can('organization')
                    <x-menu-item title="مدیریت واحدها" icon="o-building-office-2" link="/units" wire:navigate />
                    @endcan
                    {{-- پرسنل — روت خواندن union است (#775): kargozini یا manage_personnel --}}
                    @canany(['kargozini', 'manage_personnel'])
                    <x-menu-item title="پرسنل" icon="o-user-group" link="/kargozini/persons" wire:navigate />
                    @endcanany
                    @can('view_hr_dashboard')
                    <x-menu-item title="آمار پرسنل" icon="o-chart-bar" link="/hr-dashboard" wire:navigate />
                    @endcan
                    @canany(['manage_org_chart', 'view_hr_dashboard'])
                    <x-menu-item title="چارت سازمانی" icon="o-beaker" link="/hr/org-chart" wire:navigate />
                    @endcanany
                </x-menu-sub>
                @endcanany

                {{-- بیماری‌ها (#1246) — گیت منو و گیت هر چهار روت یکی است:
                     `role_or_permission:manage_diseases`. --}}
                @can('manage_diseases')
                <x-menu-sub title="بیماری‌ها" icon="o-heart">
                    <x-menu-item title="ثبت کیس" icon="o-plus-circle" link="/disease/create" wire:navigate />
                    <x-menu-item title="لیست کیس‌ها" icon="o-list-bullet" link="/disease" wire:navigate />
                    <x-menu-item title="بیماران" icon="o-user-group" link="/disease/patients" wire:navigate />
                    <x-menu-item title="مدیریت بیماری‌ها" icon="o-cog-6-tooth" link="/disease/manage" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- کار با نقشه --}}
                @can('map')
                <x-menu-sub title="کار با نقشه" icon="o-map">
                    <x-menu-item title="داشبورد GIS" icon="o-chart-bar" link="/map" wire:navigate />
                    <x-menu-item title="نقشه واحدها" icon="o-building-library" link="/maps/unit" wire:navigate />
                    <x-menu-item title="مسیر" icon="o-map" link="/maps/route" wire:navigate />
                    <x-menu-item title="یافتن مسیر" icon="o-magnifying-glass-circle" link="/maps/route2" wire:navigate />
                    <x-menu-item title="شهرستان‌ها" icon="o-map-pin" link="/maps/county" wire:navigate />
                    <x-menu-item title="نقشه نقاط" icon="o-map" link="/maps/point" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- ابزارهای مدیریتی (IT + ابزارها) — گیت گروه = اجتماع گیت‌های آیتم‌هاست،
                     ولی هر آیتم گیتِ همان روت خودش را نگه می‌دارد تا هیچ آیتمِ بی‌دسترسی
                     رندر نشود و هیچ دارندهٔ گیتی آیتمش را از دست ندهد:
                     شبکه/وایرلس → `map` یا `bw`، زبیکس → `manage_zabbix`،
                     کش سرور → `op-cache` (فقط local)، ابزارها (`/tools`) → `manage_users`.
                     تاریخچه: قبلاً کل گروه زیر `bw` بود، پس دارندهٔ `map` بدون `bw`
                     صفحه‌ها را پنهان می‌دید و دارندهٔ `bw` بدون `map` با ۴۰۳ روبه‌رو می‌شد. --}}
                @canany(['map', 'bw', 'manage_zabbix', 'op-cache', 'manage_users'])
                <x-menu-sub title="ابزارهای مدیریتی" icon="o-wrench-screwdriver">
                    @canany(['map', 'bw'])
                    <x-menu-item title="شبکه‌ها" icon="o-globe-alt" link="/it/networks" wire:navigate />
                    <x-menu-item title="وایرلس‌ها" icon="o-signal" link="/it/wireless" wire:navigate />
                    @endcanany
                    @can('manage_zabbix')
                    <x-menu-item title="مدیریت دستگاه‌های زبیکس" icon="o-server-stack" link="/it/zabbix-devices" wire:navigate />
                    @endcan
                    {{-- `op-cache` gate: /op route only exists when app()->isLocal() (routes/web.php). --}}
                    @can('op-cache')
                    @if (app()->isLocal())
                    <a href="/op" class="flex items-center gap-3 px-4 py-2 text-sm rounded-lg hover:bg-base-200 transition-colors">
                        <x-icon name="o-server" class="w-5 h-5" />
                        <span>کش سرور</span>
                    </a>
                    @endif
                    @endcan
                    {{-- `/tools` پشت `manage_users` است، نه گیت گروه — همان گیت روتش در
                         routes/web.php. آخر گروه می‌ماند تا آیتم‌های IT پیوسته و بی‌جابه‌جایی بمانند. --}}
                    @can('manage_users')
                    <x-menu-item title="ابزارها" icon="o-wrench" link="/tools" wire:navigate />
                    @endcan
                </x-menu-sub>
                @endcanany

                {{-- سخت‌افزار — /hardware و /maintenance هر دو پشت `manage_hardware`؛
                     قبلاً داخل گروه IT بودند و دارندگان manage_hardware بدون `bw` آن‌ها را نمی‌دیدند. --}}
                @can('manage_hardware')
                <x-menu-sub title="سخت‌افزار" icon="o-cpu-chip">
                    <x-menu-item title="شناسنامه سخت افزار" icon="o-cpu-chip" link="/hardware" wire:navigate />
                    <x-menu-item title="زمانبندی تعمیرات" icon="o-wrench-screwdriver" link="/maintenance" wire:navigate />
                </x-menu-sub>
                @endcan

                {{-- گزارش‌ها — روت‌ها پشت `manage_personnel` هستند؛ «گزارش فعالیت»
                     جدا پشت `manage_users` است، پس گروه برای هر دو باز می‌شود. --}}
                @canany(['manage_personnel', 'manage_users'])
                <x-menu-sub title="گزارش‌ها" icon="o-chart-bar">
                    @can('manage_personnel')
                    <x-menu-item title="گزارش واحدها و مراکز" icon="o-building-office" link="/reports/units" wire:navigate />
                    <x-menu-item title="گزارش پرسنل" icon="o-users" link="/reports/persons" wire:navigate />
                    <x-menu-item title="گزارش وظایف" icon="o-check-circle" link="/reports/todos" wire:navigate />
                    <x-menu-item title="نقاط فاقد مرز" icon="o-no-symbol" link="/reports/map-no-boundary" wire:navigate />
                    <x-menu-item title="گزارش تیکت‌ها" icon="o-ticket" link="/reports/tickets" wire:navigate />
                    @endcan
                    @can('manage_users')
                    <x-menu-item title="گزارش فعالیت" icon="o-clock" link="/activity-log" wire:navigate />
                    @endcan
                </x-menu-sub>
                @endcanany

                {{-- مدیریت سیستم --}}
                @canany(['manage_users', 'manage_roles'])
                <x-menu-sub title="مدیریت" icon="o-cog-6-tooth">
                    @can('manage_users')
                    {{-- #996: `/users/changepassword` آیتم مستقل دارد؛ بدون `:exact`
                         آیتم `/users` آن صفحه را هم ادعا می‌کرد. --}}
                    <x-menu-item title="کاربران" icon="o-users" link="/users" :exact="true" wire:navigate />
                    @endcan
                    @can('manage_roles')
                    <x-menu-item title="مدیریت نقش‌ها" icon="o-shield-check" link="/roles" wire:navigate />
                    <x-menu-item title="مدیریت دسترسی‌ها" icon="o-lock-closed" link="/permissions" wire:navigate />
                    @endcan
                </x-menu-sub>
                @endcanany

                <x-menu-separator />

                {{-- راهنما و پشتیبانی --}}
                <x-menu-separator />
                <x-menu-sub title="راهنما و پشتیبانی" icon="o-question-mark-circle">
                    <x-menu-item title="گزارش خطا" icon="o-beaker" link="/tickets/new?category=bug" wire:navigate />
                    <x-menu-item title="تماس با پشتیبانی" icon="o-chat-bubble-left-right" link="/tickets/new?category=support" wire:navigate />
                </x-menu-sub>

                {{-- حساب کاربری --}}
                <x-menu-item title="پروفایل من" icon="o-user-circle" link="/profile" wire:navigate />
                <x-menu-item title="تغییر رمز عبور" icon="o-lock-closed" link="/users/changepassword" wire:navigate />
                <x-menu-item title="تنظیمات" icon="o-cog-6-tooth" link="/settings" wire:navigate />
                </x-menu>
            </nav>
        </x-slot:sidebar>
        {{-- #957: هدف لینک پرش. Mary's Main روی `<main>` هیچ کیسهٔ صفتی نمی‌دهد،
             اما صفت‌های این اسلات را روی div محتوا merge می‌کند؛ `tabindex="-1"`
             بدون آن لازم است چون مرورگر فقط با فوکوس برنامه‌نویسی‌شده کار می‌کند. --}}
        <x-slot:content id="main-content" tabindex="-1">
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{-- #957: توست تا حالا فقط بصری بود؛ `x-show` آن را در DOM نگه می‌دارد
         ولی بدون ناحیهٔ زنده هیچ صفحه‌خوانی متوجه باز شدنش نمی‌شود. زنده‌بودن
         باید روی ظرفی باشد که همیشه در DOM است، نه روی خودِ توست. --}}
    <div aria-live="polite" aria-atomic="true">
        <x-csp-toast />
    </div>
    <!-- <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> -->
<script nonce="{{ Vite::cspNonce() }}">
    // Register service worker for browser notifications
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    document.addEventListener('livewire:init', () => {
       Livewire.on('swal', (event) => {
           const data = event[0];
           Swal.fire({
               title: data.title,
               icon: data.icon,
               confirmButtonText: 'تایید',
               timer: 3000,
               toast: true,
               position: 'top-end'
           });
       });

       // Browser notification listener
       Livewire.on('browser-notification', (data) => {
           if ('Notification' in window && Notification.permission === 'granted') {
               new Notification(data[0].title, { body: data[0].body });
           }
       });
    });
</script>

@stack('scripts')

</body>

</html>