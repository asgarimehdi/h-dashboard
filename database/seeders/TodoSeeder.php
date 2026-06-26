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

        $titles = [
            'جلسه برنامه‌ریزی هفتگی',
            'بررسی گزارش عملکرد',
            'آموزش نیروی جدید',
            'نشست فنی تیم',
            'ارائه گزارش ماهانه',
            'بازرسی تجهیزات',
            'به‌روزرسانی مستندات',
            'جلسه هماهنگی واحدها',
            'بررسی درخواست‌های جاری',
            'طراحی فرآیند جدید',
            'ارزیابی عملکرد پرسنل',
            'پیگیری مسائل فنی',
            'تهیه بودجه پیشنهادی',
            'بررسی اسناد مالی',
            'هماهنگی با واحدهای ستادی',
            'شناسایی نیازهای آموزشی',
            'تهیه برنامه زمان‌بندی پروژه',
            'بررسی عملکرد شبکه',
            'جلسه شورای فنی',
            'انجام تست‌های امنیتی',
        ];

        for ($day = 0; $day <= 30; $day++) {
            $date = $startOfMonth->copy()->addDays($day);

            if ($date->isFuture()) {
                break;
            }

            $todosPerDay = rand(1, 3);

            for ($i = 0; $i < $todosPerDay; $i++) {
                $creator = $users->random();
                $hour = rand(8, 16);
                $minute = rand(0, 59) > 30 ? 0 : 30;

                $startAt = $date->copy()->setTime($hour, $minute);
                $endAt = $startAt->copy()->addHours(rand(1, 3));

                Todo::create([
                    'title' => $titles[array_rand($titles)],
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'is_completed' => $date->isPast() ? (bool) rand(0, 1) : false,
                    'unit_id' => $creator->person?->u_id,
                ]);
            }
        }
    }
}
