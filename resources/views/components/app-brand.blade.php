@php
    $isSidebar = request()->route()?->getName() !== 'login' && request()->route()?->getName() !== 'register';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    @if($isSidebar)
        <!-- Sidebar logo with text -->
        <img src="{{ asset('logo-sidebar.svg') }}" alt="Health Dashboard" class="h-10 w-auto transition-opacity hover:opacity-80" />
    @else
        <!-- Compact logo for navbar/login -->
        <div class="flex items-center gap-1.5">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary/20 to-secondary/20 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="w-5 h-5 text-primary" fill="currentColor">
                    <!-- Heart with pulse -->
                    <path d="M32 50 C32 50, 16 38, 16 28 C16 22, 20 18, 25 18 C28 18, 30 20, 32 22 C34 20, 36 18, 39 18 C44 18, 48 22, 48 28 C48 38, 32 50, 32 50 Z" opacity="0.9"/>
                    <path d="M 10 32 Q 18 30, 22 32 L 26 28 L 30 36 L 34 32 L 38 32 Q 42 30, 50 32" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/>
                </svg>
            </div>
            <span class="font-bold text-lg hidden sm:inline">{{ config('app.name') }}</span>
        </div>
    @endif
</div>
