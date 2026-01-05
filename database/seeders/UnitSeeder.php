<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // پاک کردن جدول قبل از وارد کردن داده‌ها (اختیاری)
        DB::table('units')->truncate();

        // داده‌های ثابت از جدول units
        $units = [
            [
                'id' => 1,
                'region_id' => null, // وزارت بهداشت به منطقه خاصی وابسته نیست
                'parent_id' => null,
                'name' => 'وزارت بهداشت',
                'unit_type_id' => 1,
                'description' => 'وزارت بهداشت مرکزی',
                'created_at' => '2025-04-07 22:56:07',
                'updated_at' => '2025-04-07 22:56:07',
            ],
            [
                'id' => 2,
                'region_id' => 1, // فرض: ID=1 برای استان زنجان در جدول regions
                'parent_id' => 1,
                'name' => 'دانشگاه علوم پزشکی زنجان',
                'unit_type_id' => 2,
                'description' => null,
                'created_at' => '2025-04-07 22:58:34',
                'updated_at' => '2025-04-07 22:58:50',
            ],
            [
                'id' => 3,
                'region_id' => 1, // استان زنجان
                'parent_id' => 2,
                'name' => 'معاونت بهداشت دانشگاه علوم پزشکی زنجان',
                'unit_type_id' => 3,
                'description' => null,
                'created_at' => '2025-04-07 22:59:16',
                'updated_at' => '2025-04-07 22:59:16',
            ],
            [
                'id' => 4,
                'region_id' => 2,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان زنجان',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 5,
                'region_id' => 3, // فرض: ID=3 برای شهرستان ابهر در جدول regions
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان ابهر',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 6,
                'region_id' => 4,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان ایجرود',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 7,
                'region_id' => 5,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان خدابنده',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 8,
                'region_id' => 6,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان خرمدره',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 9,
                'region_id' => 7,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان ماهنشان',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 10,
                'region_id' => 8,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان سلطانیه',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 11,
                'region_id' => 9,
                'parent_id' => 3,
                'name' => 'شبکه بهداشت و درمان طارم',
                'unit_type_id' => 4,
                'description' => null,
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
//            [
//                'id' => 6,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 4,
//                'name' => 'مرکز خدمات جامع سلامت عباس آباد',
//                'unit_type_id' => 7,
//                'description' => null,
//                'created_at' => '2025-04-07 23:01:20',
//                'updated_at' => '2025-04-07 23:01:20',
//            ],
//            [
//                'id' => 7,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 4,
//                'name' => 'مرکز خدمات جامع سلامت حسین آباد',
//                'unit_type_id' => 6,
//                'description' => null,
//                'created_at' => '2025-04-07 23:01:48',
//                'updated_at' => '2025-04-07 23:01:48',
//            ],
//            [
//                'id' => 8,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 4,
//                'name' => 'مرکز خدمات جامع سلامت اعلایی',
//                'unit_type_id' => 5,
//                'description' => null,
//                'created_at' => '2025-04-07 23:02:13',
//                'updated_at' => '2025-04-07 23:02:13',
//            ],
//            [
//                'id' => 9,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 6,
//                'name' => 'خانه بهداشت قفس آباد',
//                'unit_type_id' => 9,
//                'description' => null,
//                'created_at' => '2025-04-07 23:02:50',
//                'updated_at' => '2025-04-07 23:02:50',
//            ],
//            [
//                'id' => 10,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 7,
//                'name' => 'خانه بهداشت فنوش آباد',
//                'unit_type_id' => 9,
//                'description' => null,
//                'created_at' => '2025-04-07 23:03:12',
//                'updated_at' => '2025-04-07 23:03:12',
//            ],
//            [
//                'id' => 11,
//                'region_id' => 2, // شهرستان ابهر
//                'parent_id' => 8,
//                'name' => 'پایگاه سلامت ضمیمه مرکز اعلایی',
//                'unit_type_id' => 10,
//                'description' => null,
//                'created_at' => '2025-04-07 23:04:28',
//                'updated_at' => '2025-04-07 23:04:28',
//            ],
        ];

        // وارد کردن داده‌ها
        foreach ($units as $unit) {
            Unit::create($unit);
        }
    }
}
