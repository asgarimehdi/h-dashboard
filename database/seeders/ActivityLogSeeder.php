<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        $types = ['login', 'logout', 'create', 'update', 'delete', 'view', 'export'];
        $descriptions = [
            'ورود موفق به سیستم',
            'خروج از سیستم',
            'ایجاد رکورد جدید',
            'بروزرسانی اطلاعات',
            'حذف رکورد',
            'مشاهده گزارش',
            'خروجی از داده‌ها',
            'تغییر تنظیمات',
            'تغییر رمز عبور',
            'مشاهده لیست کاربران',
        ];

        $now = Carbon::now();

        foreach (range(1, 50) as $i) {
            $user = $users->random();
            $daysAgo = rand(0, 60);
            $hoursAgo = rand(0, 23);

            ActivityLog::create([
                'user_id' => $user->id,
                'type' => $types[array_rand($types)],
                'description' => $descriptions[array_rand($descriptions)],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'created_at' => $now->copy()->subDays($daysAgo)->subHours($hoursAgo),
                'updated_at' => $now->copy()->subDays($daysAgo)->subHours($hoursAgo),
            ]);
        }
    }
}
