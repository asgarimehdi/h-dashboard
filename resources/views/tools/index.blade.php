<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ابزارهای مدیریتی</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-base-200">
    <div class="max-w-4xl mx-auto p-6" dir="rtl">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold flex items-center gap-2">🔧 ابزارهای مدیریتی</h1>
            <x-theme-selector/>
        </div>

        @if(session('success'))
        <div class="alert alert-success mb-4">
            <span>{{ session('success') }}</span>
        </div>
        @endif

        {{-- آمار --}}
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">تیکت‌های تکمیل شده قدیمی (۳۰+ روز)</div>
                <div class="stat-value text-lg text-warning">{{ $stats['old_tickets'] }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">لاگ‌های قدیمی (۹۰+ روز)</div>
                <div class="stat-value text-lg text-info">{{ $stats['old_activities'] }}</div>
            </div>
            <div class="stat bg-base-100 rounded-xl shadow-sm border border-base-200 p-4">
                <div class="stat-title text-xs">اعلان‌های قدیمی (۷+ روز)</div>
                <div class="stat-value text-lg text-primary">{{ $stats['old_notifications'] }}</div>
            </div>
        </div>

        {{-- ابزارها --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- آرشیو تیکت‌ها --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-sm">📦 آرشیو تیکت‌ها</h2>
                    <p class="text-xs text-base-content/60">تیکت‌های تکمیل شده قدیمی رو آرشیو کن</p>
                    <form action="{{ route('tools.archive-tickets') }}" method="POST">
                        @csrf
                        <input type="number" name="days" value="30" min="7" max="365" class="input input-bordered input-sm w-full mb-2" placeholder="تعداد روز" />
                        <button type="submit" class="btn btn-warning btn-sm w-full">آرشیو کن</button>
                    </form>
                </div>
            </div>

            {{-- پاک‌سازی لاگ‌ها --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-sm">🗑️ پاک‌سازی لاگ‌ها</h2>
                    <p class="text-xs text-base-content/60">لاگ‌های قدیمی فعالیت رو پاک کن</p>
                    <form action="{{ route('tools.clean-activities') }}" method="POST">
                        @csrf
                        <input type="number" name="days" value="90" min="30" max="365" class="input input-bordered input-sm w-full mb-2" placeholder="تعداد روز" />
                        <button type="submit" class="btn btn-info btn-sm w-full">پاک کن</button>
                    </form>
                </div>
            </div>

            {{-- پاک‌سازی اعلان‌ها --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-sm">🔔 پاک‌سازی اعلان‌ها</h2>
                    <p class="text-xs text-base-content/60">اعلان‌های قدیمی رو پاک کن</p>
                    <form action="{{ route('tools.clean-notifications') }}" method="POST">
                        @csrf
                        <input type="number" name="days" value="7" min="1" max="90" class="input input-bordered input-sm w-full mb-2" placeholder="تعداد روز" />
                        <button type="submit" class="btn btn-primary btn-sm w-full">پاک کن</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center">
            <a href="/" class="btn btn-ghost btn-sm">← بازگشت به داشبورد</a>
        </div>
    </div>

    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>