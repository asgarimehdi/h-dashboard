<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js',])

</head>
<body class="min-h-screen font-sans antialiased bg-base-200">

    {{-- NAVBAR mobile only --}}
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

    {{-- MAIN --}}
    <x-main>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">

            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            {{-- MENU --}}
            <x-menu activate-by-route>

                {{-- User --}}
                @if($user = auth()->user())
                    <x-menu-separator />

                    <x-list-item :item="$user" value="name" sub-value="email" no-separator no-hover class="-mx-2 !-my-2 rounded">
                        <x-slot:actions>
                          <x-button icon="o-cog" class="btn-circle btn-ghost btn-xs" tooltip-right="changepassword" no-wire-navigate link="/users/changepassword" />
                        <x-button icon="o-power" class="btn-circle btn-ghost btn-xs" tooltip-right="logoff" no-wire-navigate link="/logout" />


                    </x-slot:actions>
                    </x-list-item>
                    <x-menu-separator />
                @endif

                <x-menu-item title="صفحه اول" icon="o-sparkles" link="/"/>


                <x-menu-sub title="مدیریت" icon="o-cog-6-tooth">
                    <x-menu-item title="کاربران" icon="o-sparkles" link="/users" />
                <x-menu-sub title="کارگزینی" icon="o-cog-6-tooth">
                    <x-menu-item title="استخدام" icon="o-sparkles" link="/kargozini/estekhdams" />
                    <x-menu-item title="ردیف سازمانی" icon="o-sparkles" link="/kargozini/radifs" />
                    <x-menu-item title="تحصیلات" icon="o-sparkles" link="/kargozini/tahsils" />
                    <x-menu-item title="سمت ها" icon="o-sparkles" link="/kargozini/semats" />
                    <x-menu-item title="پرسنل" icon="o-sparkles" link="/kargozini/persons" />
                </x-menu-sub>
                 <x-menu-sub title="ساختار سازمان" icon="o-cog-6-tooth">
                    <x-menu-item title="ایجاد واحد جدید " icon="o-sparkles" link="/units" />
                    <x-menu-item title="چارت گرافیکی " icon="o-sparkles" link="/units/chart" />
                </x-menu-sub>
                </x-menu-sub>
            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{--  TOAST area --}}
    <x-toast />
</body>
</html>
