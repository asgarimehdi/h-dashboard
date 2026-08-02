<?php

namespace Database\Seeders;

use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TodoSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::with('person')->get();

        if ($users->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();

        // 50 Persian todo titles
        $titles = [
            'جلسه برنامه‌ریزی هفتگی',
            'بررسی گزارش عملکرد ماهانه',
            'آموزش نیروهای جدید واحد',
            'نشست فنی تیم توسعه',
            'ارائه گزارش پیشرفت پروژه',
            'بازرسی تجهیزات و سخت‌افزارها',
            'به‌روزرسانی مستندات فنی',
            'جلسه هماهنگی بین واحدها',
            'بررسی درخواست‌های جاری کاربران',
            'طراحی فرآیند جدید ثبت‌نام',
            'ارزیابی عملکرد پرسنل',
            'پیگیری مسائل فنی گزارش‌شده',
            'تهیه بودجه پیشنهادی سال آینده',
            'بررسی اسناد مالی و حسابداری',
            'هماهنگی با واحدهای ستادی',
            'شناسایی نیازهای آموزشی پرسنل',
            'تهیه برنامه زمان‌بندی پروژه‌ها',
            'بررسی عملکرد شبکه و سرورها',
            'جلسه شورای فنی هفتگی',
            'انجام تست‌های امنیتی سیستم',
            'بررسی لاگ‌های خطای سرور',
            'به‌روزرسانی پچ‌های امنیتی',
            'بررسی بک‌آپ‌های دیتابیس',
            'تنظیم مجدد فایروال شبکه',
            'نظافت فیزیکی سرورها',
            'چک‌لیست صحت‌وسلامت کاربران',
            'جلسه بازخورد کاربران',
            'بررسی نظرات و پیشنهادات',
            'به‌روزرسانی FAQ سیستم',
            'تهیه گزارش-performance',
            'مطالعه اسناد جدید استانداردها',
            'بررسی مجوزهای دسترسی کاربران',
            'جایگزینی سخت‌افزارهای معیوب',
            'نصب آپدیت‌های نرم‌افزاری',
            'بررسی فضای دیسک سرورها',
            'تنظیم نرخ ذخیره‌سازی لاگ‌ها',
            'بررسی ترافیک غیرعادی شبکه',
            'جلسه آمادگی برای بخش‌نامه',
            'ارائه.demo برای مدیریت',
            'بررسی وضعیت بک‌آپ آفلاین',
            'تنظیم هشدارهای مانیتورینگ',
            'بررسی اعتبار گواهی‌های SSL',
            'بهینه‌سازی کوئری‌های کند',
            'جلسه بررسی باگ‌های گزارش‌شده',
            'تهیه چک‌لیست پیاده‌سازی',
            'بررسی پایگاه داده‌های توزیع‌شده',
            'تنظیم سیاست‌های نگهداری داده',
            'بررسی انسجام داده‌ها',
            'جلسه هماهنگی با تأمین‌کننده‌ها',
            'تهیه گزارش نهایی ماهانه',
        ];

        // Ensure we have exactly 50 todos distributed among users
        $totalTodos = 50;
        $todosCreated = 0;

        while ($todosCreated < $totalTodos) {
            $day = rand(0, 27); // First 28 days of month
            $date = $startOfMonth->copy()->addDays($day);
            
            // Don't create future todos
            if ($date->isFuture()) {
                continue;
            }

            $creator = $users->random();
            $hour = rand(8, 16);
            $minute = rand(0, 1) ? 0 : 30;
            
            $startAt = $date->copy()->setTime($hour, $minute);
            $endAt = $startAt->copy()->addHours(rand(1, 3));
            
            $title = $titles[array_rand($titles)];
            
            // Add variation to title for uniqueness
            $variations = ['', ' - ادامه', ' - جلسه دوم', ' - پیگیری', ' - بررسی نهایی', ' - فوران', ' - اولویت بالا', ' - معمولی'];
            $title .= $variations[array_rand($variations)];

            Todo::create([
                'title' => $title,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_completed' => $date->isPast() ? (bool) rand(0, 1) : false,
                'unit_id' => $creator->person?->u_id,
                'user_id' => $creator->id,
            ]);

            $todosCreated++;
        }
    }
}