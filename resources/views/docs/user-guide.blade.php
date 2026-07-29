@extends('components.layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="bg-base-100 rounded-box shadow-xl p-6 md:p-10">
        {{-- Navigation breadcrumbs --}}
        <nav class="mb-6 text-sm text-base-content/60" dir="rtl">
            <a href="{{ route('docs.user-guide') }}" class="hover:text-primary">راهنمای کاربر</a>
            <span class="mx-2">/</span>
            <span class="text-base-content/40">{{ $page }}</span>
        </nav>

        {{-- Chapter navigation --}}
        @if($page === 'index')
            <div class="mb-8">
                <h1 class="text-3xl font-bold mb-2 text-right">راهنمای کاربر داشبورت سلامت</h1>
                <p class="text-base-content/70 text-right mb-6">نسخه ۱.۰ — ژوئیه ۲۰۲۶ — زبان فارسی</p>
            </div>
        @else
            <div class="mb-6 flex justify-between items-center">
                <a href="{{ route('docs.user-guide') }}" class="btn btn-ghost btn-sm">
                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                    بازگشت به فهرست
                </a>
            </div>
        @endif

        {{-- Rendered Markdown Content --}}
        <div class="prose prose-lg max-w-none text-right" dir="rtl">
            {!! $content !!}
        </div>

        {{-- Chapter navigation at bottom --}}
        @if($page !== 'index')
            <div class="mt-10 pt-6 border-t border-base-200 text-center">
                <a href="{{ route('docs.user-guide') }}" class="btn btn-outline btn-sm">
                    <x-icon name="o-book-open" class="w-4 h-4" />
                    فهرست تمام فصل‌ها
                </a>
            </div>
        @endif
    </div>
</div>

{{-- Quick jump to other chapters --}}
@if($page !== 'index')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="bg-base-100 rounded-box shadow p-4">
        <h3 class="font-bold mb-3 text-right">سایر فصل‌های راهنما</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
            <a href="{{ route('docs.user-guide', '00-introduction') }}" class="btn btn-ghost btn-sm justify-start">مقدمه</a>
            <a href="{{ route('docs.user-guide', '01-login-profile') }}" class="btn btn-ghost btn-sm justify-start">ورود و پروفایل</a>
            <a href="{{ route('docs.user-guide', '02-unit-context') }}" class="btn btn-ghost btn-sm justify-start">واحد سازمانی</a>
            <a href="{{ route('docs.user-guide', '03-personnel-management') }}" class="btn btn-ghost btn-sm justify-start">مدیریت پرسنل</a>
            <a href="{{ route('docs.user-guide', '04-ticket-system') }}" class="btn btn-ghost btn-sm justify-start">بلیطینگ و تیکت</a>
            <a href="{{ route('docs.user-guide', '05-map-features') }}" class="btn btn-ghost btn-sm justify-start">نقشه و مکان‌یابی</a>
            <a href="{{ route('docs.user-guide', '06-hardware-inventory') }}" class="btn btn-ghost btn-sm justify-start">سخت‌افزار</a>
            <a href="{{ route('docs.user-guide', '07-reports') }}" class="btn btn-ghost btn-sm justify-start">گزارش‌گیری</a>
            <a href="{{ route('docs.user-guide', '08-it-monitoring') }}" class="btn btn-ghost btn-sm justify-start">نظارت IT</a>
            <a href="{{ route('docs.user-guide', '09-admin-settings') }}" class="btn btn-ghost btn-sm justify-start">تنظیمات</a>
            <a href="{{ route('docs.user-guide', '10-in-app-help') }}" class="btn btn-ghost btn-sm justify-start">راهنمای درون‌برنامه</a>
        </div>
    </div>
</div>
@endif
@endsection