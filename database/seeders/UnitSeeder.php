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
                'lat' => '36.669343',
                'lng' => '48.47163',
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
                'lat' => '36.669343',
                'lng' => '48.47163',
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
                'lat' => '36.669488',
                'lng' => '48.470819',
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
                'lat' => '36.147246',
                'lng' => '49.22877',
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
                'lat' => '36.446004',
                'lng' => '48.299503',
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
                'lat' => '36.130462',
                'lng' => '48.590609',
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
                'lat' => '36.20523',
                'lng' => '49.188122',
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
                'lat' => '36.739515',
                'lng' => '47.685113',
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
                'lat' => '36.434293',
                'lng' => '48.789264',
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
                'lat' => '36.931218',
                'lng' => '48.943604',
                'created_at' => '2025-04-07 23:00:12',
                'updated_at' => '2025-04-07 23:00:12',
            ],
            [
                'id' => 12,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'ایثار',
                'unit_type_id' => 19,
                'lat' => 36.423907,
                'lng' => 48.293624,
                'boundary_id' => null
            ],
            [
                'id' => 13,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'هاری',
                'unit_type_id' => 13,
                'lat' => 36.440653,
                'lng' => 48.294096,
                'boundary_id' => null
            ],
            [
                'id' => 14,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره1',
                'unit_type_id' => 5,
                'lat' => 36.197142,
                'lng' => 49.201906,
                'boundary_id' => null
            ],
            [
                'id' => 15,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره 2',
                'unit_type_id' => 5,
                'lat' => 36.206326,
                'lng' => 49.189431,
                'boundary_id' => null
            ],
            [
                'id' => 16,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره 3',
                'unit_type_id' => 5,
                'lat' => 36.206107,
                'lng' => 49.198835,
                'boundary_id' => null
            ],
            [
                'id' => 17,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره 4',
                'unit_type_id' => 5,
                'lat' => 36.219662,
                'lng' => 49.194912,
                'boundary_id' => null
            ],
            [
                'id' => 18,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره 5',
                'unit_type_id' => 5,
                'lat' => 36.194044,
                'lng' => 49.186922,
                'boundary_id' => null
            ],
            [
                'id' => 19,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'بیمارستان بوعلی سینا',
                'unit_type_id' => 19,
                'lat' => 36.197763,
                'lng' => 49.205291,
                'boundary_id' => null
            ],
            [
                'id' => 20,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز هاری',
                'unit_type_id' => 13,
                'lat' => 36.205235,
                'lng' => 49.1881,
                'boundary_id' => null
            ],
            [
                'id' => 21,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز پری',
                'unit_type_id' => 7,
                'lat' => 36.877279,
                'lng' => 47.416427,
                'boundary_id' => null
            ],
            [
                'id' => 22,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز خیرآباد',
                'unit_type_id' => 7,
                'lat' => 36.92441,
                'lng' => 47.38318,
                'boundary_id' => null
            ],
            [
                'id' => 23,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز سهند علیا',
                'unit_type_id' => 7,
                'lat' => 36.776633,
                'lng' => 47.518426,
                'boundary_id' => null
            ],
            [
                'id' => 24,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز قره گل',
                'unit_type_id' => 7,
                'lat' => 36.602529,
                'lng' => 47.834637,
                'boundary_id' => null
            ],
            [
                'id' => 25,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز قلعه جوق',
                'unit_type_id' => 7,
                'lat' => 36.421439,
                'lng' => 47.852651,
                'boundary_id' => null
            ],
            [
                'id' => 26,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي ارمغانخانه',
                'unit_type_id' => 7,
                'lat' => 36.975818,
                'lng' => 48.370335,
                'boundary_id' => null
            ],
            [
                'id' => 27,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز دوزکند',
                'unit_type_id' => 7,
                'lat' => 36.509475,
                'lng' => 47.898306,
                'boundary_id' => null
            ],
            [
                'id' => 28,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز قره ناس',
                'unit_type_id' => 7,
                'lat' => 36.681321,
                'lng' => 47.476019,
                'boundary_id' => null
            ],
            [
                'id' => 29,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي بهرام بيگ',
                'unit_type_id' => 7,
                'lat' => 37.059028,
                'lng' => 48.249545,
                'boundary_id' => null
            ],
            [
                'id' => 30,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز ماهنشان',
                'unit_type_id' => 6,
                'lat' => 36.742489,
                'lng' => 47.670155,
                'boundary_id' => null
            ],
            [
                'id' => 31,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکز دندی',
                'unit_type_id' => 6,
                'lat' => 36.551787,
                'lng' => 47.619592,
                'boundary_id' => null
            ],
            [
                'id' => 32,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي بوغداکندي',
                'unit_type_id' => 7,
                'lat' => 36.574232,
                'lng' => 48.146648,
                'boundary_id' => null
            ],
            [
                'id' => 33,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز صائین قلعه',
                'unit_type_id' => 6,
                'lat' => 36.313619,
                'lng' => 49.068231,
                'boundary_id' => null
            ],
            [
                'id' => 34,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي تهم',
                'unit_type_id' => 7,
                'lat' => 36.792266,
                'lng' => 48.567854,
                'boundary_id' => null
            ],
            [
                'id' => 35,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'مرکزمیانج',
                'unit_type_id' => 7,
                'lat' => 36.52921,
                'lng' => 47.481632,
                'boundary_id' => null
            ],
            [
                'id' => 36,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'بیمارستان رازی',
                'unit_type_id' => 19,
                'lat' => 36.737443,
                'lng' => 47.680682,
                'boundary_id' => null
            ],
            [
                'id' => 37,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'ماهنشان',
                'unit_type_id' => 13,
                'lat' => 36.742604,
                'lng' => 47.670023,
                'boundary_id' => null
            ],
            [
                'id' => 38,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي حاج آرش',
                'unit_type_id' => 7,
                'lat' => 36.684039,
                'lng' => 48.160234,
                'boundary_id' => null
            ],
            [
                'id' => 39,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي حصار',
                'unit_type_id' => 7,
                'lat' => 36.98707,
                'lng' => 47.717884,
                'boundary_id' => null
            ],
            [
                'id' => 40,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي ديزج آباد',
                'unit_type_id' => 7,
                'lat' => 36.630528,
                'lng' => 48.600056,
                'boundary_id' => null
            ],
            [
                'id' => 41,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز عمید آباد',
                'unit_type_id' => 7,
                'lat' => 36.361166,
                'lng' => 48.953716,
                'boundary_id' => null
            ],
            [
                'id' => 42,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز هیدج',
                'unit_type_id' => 5,
                'lat' => 36.258809,
                'lng' => 49.135988,
                'boundary_id' => null
            ],
            [
                'id' => 43,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي رازبين',
                'unit_type_id' => 7,
                'lat' => 36.625275,
                'lng' => 48.412778,
                'boundary_id' => null
            ],
            [
                'id' => 44,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي سلطان آباد',
                'unit_type_id' => 7,
                'lat' => 37.079901,
                'lng' => 47.937643,
                'boundary_id' => null
            ],
            [
                'id' => 45,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي سهرين',
                'unit_type_id' => 7,
                'lat' => 36.866043,
                'lng' => 48.412006,
                'boundary_id' => null
            ],
            [
                'id' => 46,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي قره بوطه',
                'unit_type_id' => 7,
                'lat' => 37.056403,
                'lng' => 47.767709,
                'boundary_id' => null
            ],
            [
                'id' => 47,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز شماره 5',
                'unit_type_id' => 5,
                'lat' => 36.139722,
                'lng' => 49.205611,
                'boundary_id' => null
            ],
            [
                'id' => 48,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز اعلایی',
                'unit_type_id' => 5,
                'lat' => 36.149778,
                'lng' => 49.217056,
                'boundary_id' => null
            ],
            [
                'id' => 49,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز 17 شهریور',
                'unit_type_id' => 5,
                'lat' => 36.140139,
                'lng' => 49.217639,
                'boundary_id' => null
            ],
            [
                'id' => 50,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي قلتوق',
                'unit_type_id' => 7,
                'lat' => 36.497216,
                'lng' => 48.074217,
                'boundary_id' => null
            ],
            [
                'id' => 51,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي لولک آباد',
                'unit_type_id' => 7,
                'lat' => 36.744621,
                'lng' => 47.862239,
                'boundary_id' => null
            ],
            [
                'id' => 52,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي نيک پي',
                'unit_type_id' => 7,
                'lat' => 36.850182,
                'lng' => 48.178544,
                'boundary_id' => null
            ],
            [
                'id' => 53,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي چير',
                'unit_type_id' => 7,
                'lat' => 36.728347,
                'lng' => 48.303784,
                'boundary_id' => null
            ],
            [
                'id' => 54,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستايي یامچی',
                'unit_type_id' => 7,
                'lat' => 36.776987,
                'lng' => 48.209717,
                'boundary_id' => null
            ],
            [
                'id' => 55,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت روستایی اندآباد',
                'unit_type_id' => 7,
                'lat' => 36.807423,
                'lng' => 47.989972,
                'boundary_id' => null
            ],
            [
                'id' => 56,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 10',
                'unit_type_id' => 5,
                'lat' => 36.677287,
                'lng' => 48.509893,
                'boundary_id' => null
            ],
            [
                'id' => 57,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 11',
                'unit_type_id' => 5,
                'lat' => 36.666023,
                'lng' => 48.547055,
                'boundary_id' => null
            ],
            [
                'id' => 58,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 12',
                'unit_type_id' => 5,
                'lat' => 36.683401,
                'lng' => 48.49439,
                'boundary_id' => null
            ],
            [
                'id' => 59,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 13',
                'unit_type_id' => 5,
                'lat' => 36.664516,
                'lng' => 48.468134,
                'boundary_id' => null
            ],
            [
                'id' => 60,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 14',
                'unit_type_id' => 5,
                'lat' => 36.691349,
                'lng' => 48.509548,
                'boundary_id' => null
            ],
            [
                'id' => 61,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 15',
                'unit_type_id' => 5,
                'lat' => 36.696642,
                'lng' => 48.451873,
                'boundary_id' => null
            ],
            [
                'id' => 62,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 16',
                'unit_type_id' => 5,
                'lat' => 36.701394,
                'lng' => 48.480901,
                'boundary_id' => null
            ],
            [
                'id' => 63,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 17',
                'unit_type_id' => 5,
                'lat' => 36.683778,
                'lng' => 48.530388,
                'boundary_id' => null
            ],
            [
                'id' => 64,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 18',
                'unit_type_id' => 5,
                'lat' => 36.670712,
                'lng' => 48.553828,
                'boundary_id' => null
            ],
            [
                'id' => 65,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 2',
                'unit_type_id' => 5,
                'lat' => 36.674137,
                'lng' => 48.461949,
                'boundary_id' => null
            ],
            [
                'id' => 66,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 3',
                'unit_type_id' => 5,
                'lat' => 36.676045,
                'lng' => 48.485217,
                'boundary_id' => null
            ],
            [
                'id' => 67,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 4',
                'unit_type_id' => 5,
                'lat' => 36.664125,
                'lng' => 48.495166,
                'boundary_id' => null
            ],
            [
                'id' => 68,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 6',
                'unit_type_id' => 5,
                'lat' => 36.690783,
                'lng' => 48.46932,
                'boundary_id' => null
            ],
            [
                'id' => 69,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 7',
                'unit_type_id' => 5,
                'lat' => 36.671247,
                'lng' => 48.499471,
                'boundary_id' => null
            ],
            [
                'id' => 70,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز اولی بیک',
                'unit_type_id' => 7,
                'lat' => 35.938806,
                'lng' => 48.1485,
                'boundary_id' => null
            ],
            [
                'id' => 71,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 8',
                'unit_type_id' => 5,
                'lat' => 36.679163,
                'lng' => 48.469566,
                'boundary_id' => null
            ],
            [
                'id' => 72,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهري شماره 9',
                'unit_type_id' => 5,
                'lat' => 36.661286,
                'lng' => 48.514365,
                'boundary_id' => null
            ],
            [
                'id' => 73,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهری شماره 1',
                'unit_type_id' => 5,
                'lat' => 36.669332,
                'lng' => 48.471336,
                'boundary_id' => null
            ],
            [
                'id' => 74,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز خدمات جامع سلامت شهری روستايي شماره 5',
                'unit_type_id' => 6,
                'lat' => 36.672932,
                'lng' => 48.481604,
                'boundary_id' => null
            ],
            [
                'id' => 75,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز آقبلاغ',
                'unit_type_id' => 7,
                'lat' => 36.160667,
                'lng' => 48.371806,
                'boundary_id' => null
            ],
            [
                'id' => 76,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز باشقشلاق',
                'unit_type_id' => 7,
                'lat' => 36.04625,
                'lng' => 48.080194,
                'boundary_id' => null
            ],
            [
                'id' => 77,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز بولاماجی',
                'unit_type_id' => 7,
                'lat' => 36.352694,
                'lng' => 48.586222,
                'boundary_id' => null
            ],
            [
                'id' => 78,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'خانه بهداشت محمدآباد',
                'unit_type_id' => 7,
                'lat' => 36.352694,
                'lng' => 48.616028,
                'boundary_id' => null
            ],
            [
                'id' => 79,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز حسام آباد',
                'unit_type_id' => 7,
                'lat' => 35.873985,
                'lng' => 48.4078,
                'boundary_id' => null
            ],
            [
                'id' => 80,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز حصار',
                'unit_type_id' => 7,
                'lat' => 36.064583,
                'lng' => 48.576611,
                'boundary_id' => null
            ],
            [
                'id' => 81,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز دوتپه سفلی',
                'unit_type_id' => 7,
                'lat' => 36.1305,
                'lng' => 48.829028,
                'boundary_id' => null
            ],
            [
                'id' => 82,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز زاغج',
                'unit_type_id' => 7,
                'lat' => 35.813861,
                'lng' => 48.584889,
                'boundary_id' => null
            ],
            [
                'id' => 83,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز قیاسکندی',
                'unit_type_id' => 7,
                'lat' => 35.698583,
                'lng' => 48.435472,
                'boundary_id' => null
            ],
            [
                'id' => 84,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز مزیدآباد',
                'unit_type_id' => 7,
                'lat' => 36.201722,
                'lng' => 48.602778,
                'boundary_id' => null
            ],
            [
                'id' => 85,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز محمودآباد',
                'unit_type_id' => 7,
                'lat' => 36.009417,
                'lng' => 48.678417,
                'boundary_id' => null
            ],
            [
                'id' => 86,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز نعلبندان',
                'unit_type_id' => 7,
                'lat' => 36.126361,
                'lng' => 48.66225,
                'boundary_id' => null
            ],
            [
                'id' => 87,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => ' جرین',
                'unit_type_id' => 6,
                'lat' => 35.934778,
                'lng' => 48.791944,
                'boundary_id' => null
            ],
            [
                'id' => 88,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز چوزوک',
                'unit_type_id' => 7,
                'lat' => 36.297444,
                'lng' => 48.432917,
                'boundary_id' => null
            ],
            [
                'id' => 89,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز کرسف',
                'unit_type_id' => 6,
                'lat' => 36.044278,
                'lng' => 48.512861,
                'boundary_id' => null
            ],
            [
                'id' => 90,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز کهلا',
                'unit_type_id' => 7,
                'lat' => 35.663806,
                'lng' => 48.681611,
                'boundary_id' => null
            ],
            [
                'id' => 91,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز توزلو',
                'unit_type_id' => 7,
                'lat' => 35.651111,
                'lng' => 48.582667,
                'boundary_id' => null
            ],
            [
                'id' => 92,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'ایران ترانسفو',
                'unit_type_id' => 18,
                'lat' => 36.649897,
                'lng' => 48.554978,
                'boundary_id' => null
            ],
            [
                'id' => 93,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'لایی ساز',
                'unit_type_id' => 18,
                'lat' => 36.598371,
                'lng' => 48.687115,
                'boundary_id' => null
            ],
            [
                'id' => 94,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'نیک ریس',
                'unit_type_id' => 18,
                'lat' => 36.607846,
                'lng' => 48.690419,
                'boundary_id' => null
            ],
            [
                'id' => 95,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'سرب وروی ملی ایران',
                'unit_type_id' => 18,
                'lat' => 36.623,
                'lng' => 48.6337,
                'boundary_id' => null
            ],
            [
                'id' => 96,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'نخ تایر صبا',
                'unit_type_id' => 18,
                'lat' => 36.603109,
                'lng' => 48.681386,
                'boundary_id' => null
            ],
            [
                'id' => 97,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'راشا',
                'unit_type_id' => 18,
                'lat' => 36.582073,
                'lng' => 48.684797,
                'boundary_id' => null
            ],
            [
                'id' => 98,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'ارس زنجان',
                'unit_type_id' => 18,
                'lat' => 36.582418,
                'lng' => 48.684325,
                'boundary_id' => null
            ],
            [
                'id' => 99,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'چشمه سار',
                'unit_type_id' => 18,
                'lat' => 36.581091,
                'lng' => 48.685634,
                'boundary_id' => null
            ],
            [
                'id' => 100,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'صانع روی',
                'unit_type_id' => 18,
                'lat' => 36.6303,
                'lng' => 48.4267,
                'boundary_id' => null
            ],
            [
                'id' => 101,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'متین روی',
                'unit_type_id' => 18,
                'lat' => 36.6305,
                'lng' => 48.4291,
                'boundary_id' => null
            ],
            [
                'id' => 102,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'کانه آرایی آریا',
                'unit_type_id' => 18,
                'lat' => 36.6305,
                'lng' => 48.4296,
                'boundary_id' => null
            ],
            [
                'id' => 103,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'زرین معدن آسیا',
                'unit_type_id' => 18,
                'lat' => 36.631251,
                'lng' => 48.429172,
                'boundary_id' => null
            ],
            [
                'id' => 104,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'ذوب روی صبا',
                'unit_type_id' => 18,
                'lat' => 36.6311,
                'lng' => 48.4277,
                'boundary_id' => null
            ],
            [
                'id' => 105,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز زرند',
                'unit_type_id' => 7,
                'lat' => 36.164583,
                'lng' => 48.522444,
                'boundary_id' => null
            ],
            [
                'id' => 106,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'سازند روی',
                'unit_type_id' => 18,
                'lat' => 36.6278,
                'lng' => 48.4281,
                'boundary_id' => null
            ],
            [
                'id' => 107,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'زرین روی',
                'unit_type_id' => 18,
                'lat' => 36.627927,
                'lng' => 48.429408,
                'boundary_id' => null
            ],
            [
                'id' => 108,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'خالص سازان روی',
                'unit_type_id' => 18,
                'lat' => 36.624276,
                'lng' => 48.427563,
                'boundary_id' => null
            ],
            [
                'id' => 109,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'صنعت روی زنگان',
                'unit_type_id' => 18,
                'lat' => 36.6383,
                'lng' => 48.321,
                'boundary_id' => null
            ],
            [
                'id' => 110,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'سمن آسا',
                'unit_type_id' => 18,
                'lat' => 36.709268,
                'lng' => 48.435223,
                'boundary_id' => null
            ],
            [
                'id' => 111,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مددبخش',
                'unit_type_id' => 18,
                'lat' => 36.7092,
                'lng' => 48.4207,
                'boundary_id' => null
            ],
            [
                'id' => 112,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'توسعه پستها',
                'unit_type_id' => 18,
                'lat' => 36.709956,
                'lng' => 48.433614,
                'boundary_id' => null
            ],
            [
                'id' => 113,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'پودررنگ کوثر',
                'unit_type_id' => 18,
                'lat' => 36.7077,
                'lng' => 48.4178,
                'boundary_id' => null
            ],
            [
                'id' => 114,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'راهان توان',
                'unit_type_id' => 18,
                'lat' => 36.7071,
                'lng' => 48.4185,
                'boundary_id' => null
            ],
            [
                'id' => 115,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرمرین سنگ',
                'unit_type_id' => 18,
                'lat' => 36.7075,
                'lng' => 48.4187,
                'boundary_id' => null
            ],
            [
                'id' => 116,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'نوردیاوران',
                'unit_type_id' => 18,
                'lat' => 36.714,
                'lng' => 48.4104,
                'boundary_id' => null
            ],
            [
                'id' => 117,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'تسکو',
                'unit_type_id' => 18,
                'lat' => 36.716011,
                'lng' => 48.429365,
                'boundary_id' => null
            ],
            [
                'id' => 118,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز زرین رود',
                'unit_type_id' => 6,
                'lat' => 35.752861,
                'lng' => 48.483222,
                'boundary_id' => null
            ],
            [
                'id' => 119,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'ریخته گری آلومینیم',
                'unit_type_id' => 18,
                'lat' => 36.069433,
                'lng' => 49.386055,
                'boundary_id' => null
            ],
            [
                'id' => 120,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز سجاس',
                'unit_type_id' => 6,
                'lat' => 36.23875,
                'lng' => 48.559583,
                'boundary_id' => null
            ],
            [
                'id' => 121,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز سهرورد',
                'unit_type_id' => 6,
                'lat' => 36.0765,
                'lng' => 48.437806,
                'boundary_id' => null
            ],
            [
                'id' => 122,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'پارس حیات',
                'unit_type_id' => 18,
                'lat' => 36.123975,
                'lng' => 49.295225,
                'boundary_id' => null
            ],
            [
                'id' => 123,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'دیسال',
                'unit_type_id' => 18,
                'lat' => 36.094886,
                'lng' => 49.33445,
                'boundary_id' => null
            ],
            [
                'id' => 124,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'ذوب آهن نگین البرز غرب',
                'unit_type_id' => 18,
                'lat' => 36.129903,
                'lng' => 49.338956,
                'boundary_id' => null
            ],
            [
                'id' => 125,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'ایریکو',
                'unit_type_id' => 18,
                'lat' => 36.129972,
                'lng' => 49.339085,
                'boundary_id' => null
            ],
            [
                'id' => 126,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'سیم وکابل',
                'unit_type_id' => 18,
                'lat' => 36.103763,
                'lng' => 49.321661,
                'boundary_id' => null
            ],
            [
                'id' => 127,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'ساینا',
                'unit_type_id' => 18,
                'lat' => 36.071302,
                'lng' => 49.395905,
                'boundary_id' => null
            ],
            [
                'id' => 128,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'ذوب البرز',
                'unit_type_id' => 18,
                'lat' => 36.125349,
                'lng' => 49.29942,
                'boundary_id' => null
            ],
            [
                'id' => 129,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'پرجین',
                'unit_type_id' => 18,
                'lat' => 36.321055,
                'lng' => 49.05756,
                'boundary_id' => null
            ],
            [
                'id' => 130,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'پرریس',
                'unit_type_id' => 18,
                'lat' => 36.321799,
                'lng' => 49.054942,
                'boundary_id' => null
            ],
            [
                'id' => 131,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'پارس دکور',
                'unit_type_id' => 18,
                'lat' => 36.322127,
                'lng' => 49.054899,
                'boundary_id' => null
            ],
            [
                'id' => 132,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'نساجی رازی',
                'unit_type_id' => 18,
                'lat' => 36.107491,
                'lng' => 49.319687,
                'boundary_id' => null
            ],
            [
                'id' => 133,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'هوراطب',
                'unit_type_id' => 18,
                'lat' => 36.26141,
                'lng' => 49.203883,
                'boundary_id' => null
            ],
            [
                'id' => 134,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مینو',
                'unit_type_id' => 18,
                'lat' => 36.198781,
                'lng' => 49.20785,
                'boundary_id' => null
            ],
            [
                'id' => 135,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'سیلیس آرا',
                'unit_type_id' => 18,
                'lat' => 36.251507,
                'lng' => 49.229221,
                'boundary_id' => null
            ],
            [
                'id' => 136,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'معدن کاران نسوز',
                'unit_type_id' => 18,
                'lat' => 36.253722,
                'lng' => 49.23068,
                'boundary_id' => null
            ],
            [
                'id' => 137,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'کشتارگاه پرطلائی',
                'unit_type_id' => 18,
                'lat' => 36.252251,
                'lng' => 49.224586,
                'boundary_id' => null
            ],
            [
                'id' => 138,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'سیمان خمسه',
                'unit_type_id' => 18,
                'lat' => 36.4127,
                'lng' => 48.2439,
                'boundary_id' => null
            ],
            [
                'id' => 139,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مجتمع زرین',
                'unit_type_id' => 18,
                'lat' => 36.388469,
                'lng' => 48.185434,
                'boundary_id' => null
            ],
            [
                'id' => 140,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'سیمان زنجان',
                'unit_type_id' => 18,
                'lat' => 36.27,
                'lng' => 48.67,
                'boundary_id' => null
            ],
            [
                'id' => 141,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'فرش سهند',
                'unit_type_id' => 18,
                'lat' => 36.517845,
                'lng' => 48.794446,
                'boundary_id' => null
            ],
            [
                'id' => 142,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'کریستال',
                'unit_type_id' => 18,
                'lat' => 36.424528,
                'lng' => 48.881199,
                'boundary_id' => null
            ],
            [
                'id' => 143,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'آرین توتون صنعت',
                'unit_type_id' => 18,
                'lat' => 36.455359,
                'lng' => 48.83543,
                'boundary_id' => null
            ],
            [
                'id' => 144,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'کالسیمین',
                'unit_type_id' => 18,
                'lat' => 36.568115,
                'lng' => 47.617407,
                'boundary_id' => null
            ],
            [
                'id' => 145,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'معدن انگوران',
                'unit_type_id' => 18,
                'lat' => 36.61,
                'lng' => 47.43,
                'boundary_id' => null
            ],
            [
                'id' => 146,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز سعیدآباد',
                'unit_type_id' => 7,
                'lat' => 36.385012,
                'lng' => 48.11901,
                'boundary_id' => null
            ],
            [
                'id' => 147,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز نکتو',
                'unit_type_id' => 7,
                'lat' => 36.249776,
                'lng' => 48.22041,
                'boundary_id' => null
            ],
            [
                'id' => 148,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز اغولبیک سفلی',
                'unit_type_id' => 7,
                'lat' => 36.435371,
                'lng' => 48.482323,
                'boundary_id' => null
            ],
            [
                'id' => 149,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز هلیل آباد',
                'unit_type_id' => 7,
                'lat' => 36.248429,
                'lng' => 48.327752,
                'boundary_id' => null
            ],
            [
                'id' => 150,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز جوقین',
                'unit_type_id' => 7,
                'lat' => 36.377137,
                'lng' => 48.316412,
                'boundary_id' => null
            ],
            [
                'id' => 151,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز ینگی کندسیدلر',
                'unit_type_id' => 7,
                'lat' => 36.453236,
                'lng' => 48.261282,
                'boundary_id' => null
            ],
            [
                'id' => 152,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکز خدمات جامع سلامت شهری روستایی شبانه روزی سلطانیه',
                'unit_type_id' => 6,
                'lat' => 36.435413,
                'lng' => 48.78854,
                'boundary_id' => null
            ],
            [
                'id' => 153,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکز سنبل آباد',
                'unit_type_id' => 7,
                'lat' => 36.432629,
                'lng' => 48.878565,
                'boundary_id' => null
            ],
            [
                'id' => 154,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکزویر',
                'unit_type_id' => 7,
                'lat' => 36.357383,
                'lng' => 48.847484,
                'boundary_id' => null
            ],
            [
                'id' => 155,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکز گوزلدره',
                'unit_type_id' => 7,
                'lat' => 36.48496,
                'lng' => 48.633471,
                'boundary_id' => null
            ],
            [
                'id' => 156,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکز خیرآباد',
                'unit_type_id' => 7,
                'lat' => 36.515784,
                'lng' => 48.780726,
                'boundary_id' => null
            ],
            [
                'id' => 157,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکزقره بلاغ',
                'unit_type_id' => 7,
                'lat' => 36.495943,
                'lng' => 48.798147,
                'boundary_id' => null
            ],
            [
                'id' => 158,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز حسین آباد',
                'unit_type_id' => 6,
                'lat' => 36.149953,
                'lng' => 49.198257,
                'boundary_id' => null
            ],
            [
                'id' => 159,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز گرماب',
                'unit_type_id' => 6,
                'lat' => 35.848306,
                'lng' => 48.205,
                'boundary_id' => null
            ],
            [
                'id' => 160,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز شماره 4 ( شناط)',
                'unit_type_id' => 6,
                'lat' => 36.159001,
                'lng' => 49.237861,
                'boundary_id' => null
            ],
            [
                'id' => 161,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز شریف آباد',
                'unit_type_id' => 6,
                'lat' => 36.130751,
                'lng' => 49.277639,
                'boundary_id' => null
            ],
            [
                'id' => 162,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز شهری شماره 2',
                'unit_type_id' => 6,
                'lat' => 36.120222,
                'lng' => 48.596222,
                'boundary_id' => null
            ],
            [
                'id' => 163,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز قروه',
                'unit_type_id' => 7,
                'lat' => 36.065582,
                'lng' => 49.387361,
                'boundary_id' => null
            ],
            [
                'id' => 164,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'ریخته گری سهند',
                'unit_type_id' => 18,
                'lat' => 36.483141,
                'lng' => 48.34362,
                'boundary_id' => null
            ],
            [
                'id' => 165,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز درسجین',
                'unit_type_id' => 7,
                'lat' => 36.021269,
                'lng' => 49.238854,
                'boundary_id' => null
            ],
            [
                'id' => 166,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز دولت آباد',
                'unit_type_id' => 7,
                'lat' => 35.978111,
                'lng' => 49.131806,
                'boundary_id' => null
            ],
            [
                'id' => 167,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز عباس آباد سفلی',
                'unit_type_id' => 7,
                'lat' => 36.077501,
                'lng' => 48.996222,
                'boundary_id' => null
            ],
            [
                'id' => 168,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز انذر',
                'unit_type_id' => 7,
                'lat' => 37.01375,
                'lng' => 48.737333,
                'boundary_id' => null
            ],
            [
                'id' => 169,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز تشویر',
                'unit_type_id' => 7,
                'lat' => 36.784014,
                'lng' => 49.009756,
                'boundary_id' => null
            ],
            [
                'id' => 170,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز درام',
                'unit_type_id' => 7,
                'lat' => 37.024707,
                'lng' => 48.775311,
                'boundary_id' => null
            ],
            [
                'id' => 171,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز سانسیز',
                'unit_type_id' => 7,
                'lat' => 36.912472,
                'lng' => 48.856781,
                'boundary_id' => null
            ],
            [
                'id' => 172,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز گیلوان',
                'unit_type_id' => 7,
                'lat' => 36.7896,
                'lng' => 49.122392,
                'boundary_id' => null
            ],
            [
                'id' => 173,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز دستجرده',
                'unit_type_id' => 7,
                'lat' => 36.850397,
                'lng' => 48.946247,
                'boundary_id' => null
            ],
            [
                'id' => 174,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'زنجان محور خودرو',
                'unit_type_id' => 18,
                'lat' => 36.71288,
                'lng' => 48.413959,
                'boundary_id' => null
            ],
            [
                'id' => 175,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'پارس سویچ',
                'unit_type_id' => 18,
                'lat' => 36.642839,
                'lng' => 48.57296,
                'boundary_id' => null
            ],
            [
                'id' => 176,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز آببر',
                'unit_type_id' => 6,
                'lat' => 36.922465,
                'lng' => 48.964327,
                'boundary_id' => null
            ],
            [
                'id' => 177,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز چورزق',
                'unit_type_id' => 6,
                'lat' => 36.992513,
                'lng' => 48.779501,
                'boundary_id' => null
            ],
            [
                'id' => 178,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'بیمارستان',
                'unit_type_id' => 19,
                'lat' => 36.930124,
                'lng' => 48.944534,
                'boundary_id' => null
            ],
            [
                'id' => 179,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز تجمیع شفیعیه',
                'unit_type_id' => 14,
                'lat' => 36.676298,
                'lng' => 48.488745,
                'boundary_id' => null
            ],
            [
                'id' => 180,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز تجمیع خدابنده',
                'unit_type_id' => 14,
                'lat' => 36.122017,
                'lng' => 48.589761,
                'boundary_id' => null
            ],
            [
                'id' => 181,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز درمان پیشگیری هاری زنجان',
                'unit_type_id' => 13,
                'lat' => 36.701189,
                'lng' => 48.509502,
                'boundary_id' => null
            ],
            [
                'id' => 182,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'بیمارستان آیت اله موسوی',
                'unit_type_id' => 19,
                'lat' => 36.701024,
                'lng' => 48.509365,
                'boundary_id' => null
            ],
            [
                'id' => 183,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز شماره 1',
                'unit_type_id' => 5,
                'lat' => 36.130667,
                'lng' => 48.594167,
                'boundary_id' => null
            ],
            [
                'id' => 184,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز شماره 3',
                'unit_type_id' => 5,
                'lat' => 36.11152,
                'lng' => 48.58505,
                'boundary_id' => null
            ],
            [
                'id' => 185,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'بیمارستان امیرالمومنین(ع) خدابنده',
                'unit_type_id' => 19,
                'lat' => 36.132372,
                'lng' => 48.593001,
                'boundary_id' => null
            ],
            [
                'id' => 186,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'شهید بهشتی',
                'unit_type_id' => 19,
                'lat' => 36.676482,
                'lng' => 48.483406,
                'boundary_id' => null
            ],
            [
                'id' => 187,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'امام حسین',
                'unit_type_id' => 19,
                'lat' => 36.687297,
                'lng' => 48.459363,
                'boundary_id' => null
            ],
            [
                'id' => 188,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'بیمارستان ولیعصر',
                'unit_type_id' => 19,
                'lat' => 36.677799,
                'lng' => 48.515518,
                'boundary_id' => null
            ],
            [
                'id' => 189,
                'region_id' => 9,
                'parent_id' => 11,
                'name' => 'مرکز درمان پیشگیری هاری طارم',
                'unit_type_id' => 13,
                'lat' => 36.922276,
                'lng' => 48.964424,
                'boundary_id' => null
            ],
            [
                'id' => 190,
                'region_id' => 8,
                'parent_id' => 10,
                'name' => 'مرکز درمان پیشگیری هاری سلطانیه',
                'unit_type_id' => 13,
                'lat' => 36.435462,
                'lng' => 48.788454,
                'boundary_id' => null
            ],
            [
                'id' => 191,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز درمان پیشگیری هاری خدابنده',
                'unit_type_id' => 13,
                'lat' => 36.120222,
                'lng' => 48.59622,
                'boundary_id' => null
            ],
            [
                'id' => 192,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز هاری بیمارستان امدادی',
                'unit_type_id' => 13,
                'lat' => 36.15335,
                'lng' => 49.234414,
                'boundary_id' => null
            ],
            [
                'id' => 193,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'بیمارستان بهمن',
                'unit_type_id' => 19,
                'lat' => 36.660777,
                'lng' => 48.549829,
                'boundary_id' => null
            ],
            [
                'id' => 194,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'بیمارستان ارتش',
                'unit_type_id' => 19,
                'lat' => 36.681972,
                'lng' => 48.486754,
                'boundary_id' => null
            ],
            [
                'id' => 195,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'بیمارستان امید ابهر',
                'unit_type_id' => 19,
                'lat' => 36.138291,
                'lng' => 49.195061,
                'boundary_id' => null
            ],
            [
                'id' => 196,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'بیمارستان الغدیر',
                'unit_type_id' => 19,
                'lat' => 36.12441,
                'lng' => 49.222409,
                'boundary_id' => null
            ],
            [
                'id' => 197,
                'region_id' => 5,
                'parent_id' => 7,
                'name' => 'مرکز گوگجه ییلاق',
                'unit_type_id' => 7,
                'lat' => 35.927129,
                'lng' => 48.568944,
                'boundary_id' => null
            ],
            [
                'id' => 198,
                'region_id' => 7,
                'parent_id' => 9,
                'name' => 'سهند سفلی',
                'unit_type_id' => 7,
                'lat' => 36.777453,
                'lng' => 47.542933,
                'boundary_id' => null
            ],
            [
                'id' => 199,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکزخدمات جامع سلامت زرین آباد',
                'unit_type_id' => 6,
                'lat' => 36.427783,
                'lng' => 48.27623,
                'boundary_id' => null
            ],
            [
                'id' => 200,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز خدمات جامع سلامت حلب',
                'unit_type_id' => 6,
                'lat' => 36.294201,
                'lng' => 48.06011,
                'boundary_id' => null
            ],
            [
                'id' => 201,
                'region_id' => 4,
                'parent_id' => 6,
                'name' => 'مرکز خدمات جامع سلامت زرین آباد',
                'unit_type_id' => 14,
                'lat' => 36.427852,
                'lng' => 48.27636,
                'boundary_id' => null
            ],
            [
                'id' => 202,
                'region_id' => 3,
                'parent_id' => 5,
                'name' => 'مرکز الگزیر',
                'unit_type_id' => 7,
                'lat' => 36.300259,
                'lng' => 48.942711,
                'boundary_id' => null
            ],
            [
                'id' => 203,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز شماره 2 روستایی',
                'unit_type_id' => 6,
                'lat' => 36.206488,
                'lng' => 49.189388,
                'boundary_id' => null
            ],
            [
                'id' => 204,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز سوکهریز',
                'unit_type_id' => 7,
                'lat' => 36.209368,
                'lng' => 49.082242,
                'boundary_id' => null
            ],
            [
                'id' => 205,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز قلعه حسینیه',
                'unit_type_id' => 7,
                'lat' => 36.239996,
                'lng' => 49.087329,
                'boundary_id' => null
            ],
            [
                'id' => 206,
                'region_id' => 6,
                'parent_id' => 8,
                'name' => 'مرکز رحمت آباد',
                'unit_type_id' => 6,
                'lat' => 36.172678,
                'lng' => 49.113446,
                'boundary_id' => null
            ],
            [
                'id' => 207,
                'region_id' => 2,
                'parent_id' => 4,
                'name' => 'مرکز دیابت',
                'unit_type_id' => 5,
                'lat' => 36.40125,
                'lng' => 48.028497,
                'boundary_id' => null
            ],
            [
                'id' => 208,
                'region_id' => 4,
                'parent_id' => 146,
                'name' => 'ملاپیری',
                'unit_type_id' => 9,
                'lat' => 36.391613,
                'lng' => 48.042698,
                'boundary_id' => null
            ],
            [
                'id' => 209,
                'region_id' => 4,
                'parent_id' => 146,
                'name' => 'شهرک',
                'unit_type_id' => 9,
                'lat' => 36.449456,
                'lng' => 48.095098,
                'boundary_id' => null
            ],
            [
                'id' => 210,
                'region_id' => 4,
                'parent_id' => 146,
                'name' => 'قزلجه',
                'unit_type_id' => 9,
                'lat' => 36.364282,
                'lng' => 48.156917,
                'boundary_id' => null
            ],
            [
                'id' => 211,
                'region_id' => 4,
                'parent_id' => 147,
                'name' => 'بهمن',
                'unit_type_id' => 9,
                'lat' => 36.126844,
                'lng' => 48.256212,
                'boundary_id' => null
            ],
            [
                'id' => 212,
                'region_id' => 4,
                'parent_id' => 147,
                'name' => 'قلقاتی',
                'unit_type_id' => 9,
                'lat' => 36.214709,
                'lng' => 48.252929,
                'boundary_id' => null
            ],
            [
                'id' => 213,
                'region_id' => 4,
                'parent_id' => 147,
                'name' => 'چسب',
                'unit_type_id' => 9,
                'lat' => 36.202897,
                'lng' => 48.174931,
                'boundary_id' => null
            ],
            [
                'id' => 214,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'اینچه رهبری',
                'unit_type_id' => 9,
                'lat' => 36.445223,
                'lng' => 48.398885,
                'boundary_id' => null
            ],
            [
                'id' => 215,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'اغولبیک علیا',
                'unit_type_id' => 9,
                'lat' => 36.480346,
                'lng' => 48.469303,
                'boundary_id' => null
            ],
            [
                'id' => 216,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'آلاچمن',
                'unit_type_id' => 9,
                'lat' => 36.422802,
                'lng' => 48.551137,
                'boundary_id' => null
            ],
            [
                'id' => 217,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'باغکندی',
                'unit_type_id' => 9,
                'lat' => 36.457654,
                'lng' => 48.430331,
                'boundary_id' => null
            ],
            [
                'id' => 218,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'باریکاب',
                'unit_type_id' => 9,
                'lat' => 36.402253,
                'lng' => 48.454986,
                'boundary_id' => null
            ],
            [
                'id' => 219,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'آقبلاغ',
                'unit_type_id' => 9,
                'lat' => 36.403107,
                'lng' => 48.523446,
                'boundary_id' => null
            ],
            [
                'id' => 220,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'قارختلو',
                'unit_type_id' => 9,
                'lat' => 36.473151,
                'lng' => 48.396631,
                'boundary_id' => null
            ],
            [
                'id' => 221,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'چتز',
                'unit_type_id' => 9,
                'lat' => 36.437518,
                'lng' => 48.446191,
                'boundary_id' => null
            ],
            [
                'id' => 222,
                'region_id' => 4,
                'parent_id' => 149,
                'name' => 'هلیل آباد',
                'unit_type_id' => 9,
                'lat' => 36.252095,
                'lng' => 48.345594,
                'boundary_id' => null
            ],
            [
                'id' => 223,
                'region_id' => 4,
                'parent_id' => 149,
                'name' => 'نجم الشیخان',
                'unit_type_id' => 9,
                'lat' => 36.285917,
                'lng' => 48.226891,
                'boundary_id' => null
            ],
            [
                'id' => 224,
                'region_id' => 4,
                'parent_id' => 150,
                'name' => 'شیوه',
                'unit_type_id' => 9,
                'lat' => 36.337474,
                'lng' => 48.389011,
                'boundary_id' => null
            ],
            [
                'id' => 225,
                'region_id' => 4,
                'parent_id' => 150,
                'name' => 'گلابر سفلی',
                'unit_type_id' => 9,
                'lat' => 36.33982,
                'lng' => 48.292669,
                'boundary_id' => null
            ],
            [
                'id' => 226,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'ایچ',
                'unit_type_id' => 9,
                'lat' => 36.453685,
                'lng' => 48.213995,
                'boundary_id' => null
            ],
            [
                'id' => 227,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'بیدگینه',
                'unit_type_id' => 9,
                'lat' => 36.505204,
                'lng' => 48.201141,
                'boundary_id' => null
            ],
            [
                'id' => 228,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'تله گرد',
                'unit_type_id' => 9,
                'lat' => 36.471468,
                'lng' => 48.233135,
                'boundary_id' => null
            ],
            [
                'id' => 229,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'اوزان',
                'unit_type_id' => 9,
                'lat' => 36.500978,
                'lng' => 48.220968,
                'boundary_id' => null
            ],
            [
                'id' => 230,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'چاپوق',
                'unit_type_id' => 9,
                'lat' => 36.523482,
                'lng' => 48.216999,
                'boundary_id' => null
            ],
            [
                'id' => 231,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'ینگی کند جامع السرا',
                'unit_type_id' => 9,
                'lat' => 36.246144,
                'lng' => 48.000035,
                'boundary_id' => null
            ],
            [
                'id' => 232,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'گنبد',
                'unit_type_id' => 9,
                'lat' => 36.152397,
                'lng' => 48.042575,
                'boundary_id' => null
            ],
            [
                'id' => 233,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'کهریز سیاه منصور',
                'unit_type_id' => 9,
                'lat' => 36.384807,
                'lng' => 47.96103,
                'boundary_id' => null
            ],
            [
                'id' => 234,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'حاجی قشلاق',
                'unit_type_id' => 9,
                'lat' => 36.298464,
                'lng' => 47.962403,
                'boundary_id' => null
            ],
            [
                'id' => 235,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'ارکوئین',
                'unit_type_id' => 9,
                'lat' => 36.314364,
                'lng' => 48.140899,
                'boundary_id' => null
            ],
            [
                'id' => 236,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'صائین',
                'unit_type_id' => 9,
                'lat' => 36.452339,
                'lng' => 47.960708,
                'boundary_id' => null
            ],
            [
                'id' => 237,
                'region_id' => 4,
                'parent_id' => 146,
                'name' => 'سعید آباد',
                'unit_type_id' => 9,
                'lat' => 36.392978,
                'lng' => 48.109936,
                'boundary_id' => null
            ],
            [
                'id' => 238,
                'region_id' => 4,
                'parent_id' => 147,
                'name' => 'نکتو',
                'unit_type_id' => 9,
                'lat' => 36.242082,
                'lng' => 48.198569,
                'boundary_id' => null
            ],
            [
                'id' => 239,
                'region_id' => 4,
                'parent_id' => 148,
                'name' => 'اغولبیک سفلی',
                'unit_type_id' => 9,
                'lat' => 36.435172,
                'lng' => 48.469234,
                'boundary_id' => null
            ],
            [
                'id' => 240,
                'region_id' => 4,
                'parent_id' => 150,
                'name' => 'جوقین',
                'unit_type_id' => 9,
                'lat' => 36.371816,
                'lng' => 48.337097,
                'boundary_id' => null
            ],
            [
                'id' => 241,
                'region_id' => 4,
                'parent_id' => 151,
                'name' => 'ینگی کند سیدلر',
                'unit_type_id' => 9,
                'lat' => 36.449801,
                'lng' => 48.249421,
                'boundary_id' => null
            ],
            [
                'id' => 242,
                'region_id' => 6,
                'parent_id' => 204,
                'name' => 'خانه سوکهریز',
                'unit_type_id' => 9,
                'lat' => 36.209368,
                'lng' => 49.082242,
                'boundary_id' => null
            ],
            [
                'id' => 243,
                'region_id' => 6,
                'parent_id' => 204,
                'name' => 'خانه ملک فلج',
                'unit_type_id' => 9,
                'lat' => 36.195555,
                'lng' => 49.04306,
                'boundary_id' => null
            ],
            [
                'id' => 244,
                'region_id' => 6,
                'parent_id' => 204,
                'name' => 'خانه اردجین',
                'unit_type_id' => 9,
                'lat' => 36.224393,
                'lng' => 49.017228,
                'boundary_id' => null
            ],
            [
                'id' => 245,
                'region_id' => 6,
                'parent_id' => 205,
                'name' => 'خانه شویر',
                'unit_type_id' => 9,
                'lat' => 36.239996,
                'lng' => 49.087329,
                'boundary_id' => null
            ],
            [
                'id' => 246,
                'region_id' => 6,
                'parent_id' => 205,
                'name' => 'خانه قلعه حسینیه',
                'unit_type_id' => 9,
                'lat' => 36.245022,
                'lng' => 49.169548,
                'boundary_id' => null
            ],
            [
                'id' => 247,
                'region_id' => 6,
                'parent_id' => 205,
                'name' => 'خانه الوند',
                'unit_type_id' => 9,
                'lat' => 36.31967,
                'lng' => 49.16716,
                'boundary_id' => null
            ],
            [
                'id' => 248,
                'region_id' => 6,
                'parent_id' => 205,
                'name' => 'خانه نصیر آباد',
                'unit_type_id' => 9,
                'lat' => 36.27889,
                'lng' => 49.119291,
                'boundary_id' => null
            ],
            [
                'id' => 249,
                'region_id' => 6,
                'parent_id' => 203,
                'name' => 'خانه بهداشت شماره 2',
                'unit_type_id' => 9,
                'lat' => 36.172678,
                'lng' => 49.113446,
                'boundary_id' => null
            ],
            [
                'id' => 250,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => 'خانه بهداشت پری',
                'unit_type_id' => 9,
                'lat' => 36.878714,
                'lng' => 47.415218,
                'boundary_id' => null
            ],
            [
                'id' => 251,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => ' خانه بهداشت تات قشلاق',
                'unit_type_id' => 9,
                'lat' => 36.820748,
                'lng' => 47.259893,
                'boundary_id' => null
            ],
            [
                'id' => 252,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => 'خانه بهداشت تخته یورد',
                'unit_type_id' => 9,
                'lat' => 36.791731,
                'lng' => 47.350101,
                'boundary_id' => null
            ],
            [
                'id' => 253,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => 'خانه بهداشت سعید کندی',
                'unit_type_id' => 9,
                'lat' => 36.805133,
                'lng' => 47.282295,
                'boundary_id' => null
            ],
            [
                'id' => 254,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => 'خانه بهداشت علم کندی',
                'unit_type_id' => 9,
                'lat' => 36.771841,
                'lng' => 47.300863,
                'boundary_id' => null
            ],
            [
                'id' => 255,
                'region_id' => 7,
                'parent_id' => 22,
                'name' => 'خانه بهداشت خیرآباد',
                'unit_type_id' => 9,
                'lat' => 36.924388,
                'lng' => 47.38324,
                'boundary_id' => null
            ],
            [
                'id' => 256,
                'region_id' => 7,
                'parent_id' => 23,
                'name' => 'خانه بهداشت سهند علیا',
                'unit_type_id' => 9,
                'lat' => 36.776019,
                'lng' => 47.520867,
                'boundary_id' => null
            ],
            [
                'id' => 257,
                'region_id' => 7,
                'parent_id' => 23,
                'name' => 'خانه بهداشت قاضی کندی',
                'unit_type_id' => 9,
                'lat' => 36.800912,
                'lng' => 47.442352,
                'boundary_id' => null
            ],
            [
                'id' => 258,
                'region_id' => 7,
                'parent_id' => 23,
                'name' => 'خانه بهداشت کردی',
                'unit_type_id' => 9,
                'lat' => 36.829388,
                'lng' => 47.501807,
                'boundary_id' => null
            ],
            [
                'id' => 259,
                'region_id' => 7,
                'parent_id' => 23,
                'name' => 'خانه بهداشت قره داش',
                'unit_type_id' => 9,
                'lat' => 36.794961,
                'lng' => 47.419324,
                'boundary_id' => null
            ],
            [
                'id' => 260,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت قره گل',
                'unit_type_id' => 9,
                'lat' => 36.601874,
                'lng' => 47.831998,
                'boundary_id' => null
            ],
            [
                'id' => 261,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت برون قشلاق',
                'unit_type_id' => 9,
                'lat' => 36.636066,
                'lng' => 47.786779,
                'boundary_id' => null
            ],
            [
                'id' => 262,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت مغانلو',
                'unit_type_id' => 9,
                'lat' => 36.638669,
                'lng' => 47.911456,
                'boundary_id' => null
            ],
            [
                'id' => 263,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت ایلی بلاغ',
                'unit_type_id' => 9,
                'lat' => 36.665998,
                'lng' => 47.748334,
                'boundary_id' => null
            ],
            [
                'id' => 264,
                'region_id' => 7,
                'parent_id' => 25,
                'name' => 'خانه بهداشت تبریزک',
                'unit_type_id' => 9,
                'lat' => 36.44357,
                'lng' => 47.884476,
                'boundary_id' => null
            ],
            [
                'id' => 265,
                'region_id' => 7,
                'parent_id' => 25,
                'name' => 'خانه بهداشت ویزمک',
                'unit_type_id' => 9,
                'lat' => 36.400181,
                'lng' => 47.80178,
                'boundary_id' => null
            ],
            [
                'id' => 266,
                'region_id' => 2,
                'parent_id' => 26,
                'name' => 'خانه ارمغانخانه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.979337,
                'lng' => 48.373054,
                'boundary_id' => null
            ],
            [
                'id' => 267,
                'region_id' => 7,
                'parent_id' => 25,
                'name' => 'خانه بهداشت قلعه جوق',
                'unit_type_id' => 9,
                'lat' => 36.420887,
                'lng' => 47.85286,
                'boundary_id' => null
            ],
            [
                'id' => 268,
                'region_id' => 7,
                'parent_id' => 27,
                'name' => 'خانه بهداشت دوزکند',
                'unit_type_id' => 9,
                'lat' => 36.511752,
                'lng' => 47.900269,
                'boundary_id' => null
            ],
            [
                'id' => 269,
                'region_id' => 7,
                'parent_id' => 27,
                'name' => 'خانه بهداشت سونتو',
                'unit_type_id' => 9,
                'lat' => 36.485337,
                'lng' => 47.780929,
                'boundary_id' => null
            ],
            [
                'id' => 270,
                'region_id' => 7,
                'parent_id' => 27,
                'name' => 'خانه بهداشت ابراهیم آباد',
                'unit_type_id' => 9,
                'lat' => 36.49919,
                'lng' => 47.81996,
                'boundary_id' => null
            ],
            [
                'id' => 271,
                'region_id' => 7,
                'parent_id' => 27,
                'name' => 'خانه بهداشت کهریزبیک',
                'unit_type_id' => 9,
                'lat' => 36.554855,
                'lng' => 47.895216,
                'boundary_id' => null
            ],
            [
                'id' => 272,
                'region_id' => 7,
                'parent_id' => 27,
                'name' => 'خانه بهداشت اغولبیک',
                'unit_type_id' => 9,
                'lat' => 36.502294,
                'lng' => 47.943985,
                'boundary_id' => null
            ],
            [
                'id' => 273,
                'region_id' => 7,
                'parent_id' => 28,
                'name' => 'خانه بهداشت قره ناس',
                'unit_type_id' => 9,
                'lat' => 36.681475,
                'lng' => 47.474967,
                'boundary_id' => null
            ],
            [
                'id' => 274,
                'region_id' => 2,
                'parent_id' => 26,
                'name' => 'خانه جلیل آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.987026,
                'lng' => 48.321821,
                'boundary_id' => null
            ],
            [
                'id' => 275,
                'region_id' => 7,
                'parent_id' => 28,
                'name' => 'خانه بهداشت خورجهان',
                'unit_type_id' => 9,
                'lat' => 36.690225,
                'lng' => 47.401679,
                'boundary_id' => null
            ],
            [
                'id' => 276,
                'region_id' => 2,
                'parent_id' => 26,
                'name' => 'خانه قره تپه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.95864,
                'lng' => 48.305324,
                'boundary_id' => null
            ],
            [
                'id' => 277,
                'region_id' => 7,
                'parent_id' => 28,
                'name' => 'خانه بهداشت اینچه سفلی',
                'unit_type_id' => 9,
                'lat' => 36.629047,
                'lng' => 47.593753,
                'boundary_id' => null
            ],
            [
                'id' => 278,
                'region_id' => 2,
                'parent_id' => 26,
                'name' => 'خانه مشکین زنجان',
                'unit_type_id' => 9,
                'lat' => 37.018774,
                'lng' => 48.378315,
                'boundary_id' => null
            ],
            [
                'id' => 279,
                'region_id' => 2,
                'parent_id' => 26,
                'name' => 'خانه گلجه زنجان',
                'unit_type_id' => 9,
                'lat' => 37.010565,
                'lng' => 48.308241,
                'boundary_id' => null
            ],
            [
                'id' => 280,
                'region_id' => 7,
                'parent_id' => 28,
                'name' => 'خانه بهداشت قشلاجوق علیا',
                'unit_type_id' => 9,
                'lat' => 36.617919,
                'lng' => 47.56206,
                'boundary_id' => null
            ],
            [
                'id' => 281,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت آلمالو',
                'unit_type_id' => 9,
                'lat' => 36.714463,
                'lng' => 47.534981,
                'boundary_id' => null
            ],
            [
                'id' => 282,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت سریک',
                'unit_type_id' => 9,
                'lat' => 36.720316,
                'lng' => 47.704926,
                'boundary_id' => null
            ],
            [
                'id' => 283,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت میرآخور',
                'unit_type_id' => 9,
                'lat' => 36.718052,
                'lng' => 47.715744,
                'boundary_id' => null
            ],
            [
                'id' => 284,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت آقکند',
                'unit_type_id' => 9,
                'lat' => 36.707215,
                'lng' => 47.57672,
                'boundary_id' => null
            ],
            [
                'id' => 285,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت بلندپرچین',
                'unit_type_id' => 9,
                'lat' => 36.663829,
                'lng' => 47.633042,
                'boundary_id' => null
            ],
            [
                'id' => 286,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت ینگجه سینار',
                'unit_type_id' => 9,
                'lat' => 36.867817,
                'lng' => 47.57736,
                'boundary_id' => null
            ],
            [
                'id' => 287,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت قره باطلاق',
                'unit_type_id' => 9,
                'lat' => 36.632252,
                'lng' => 47.764807,
                'boundary_id' => null
            ],
            [
                'id' => 288,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت ساری آغل',
                'unit_type_id' => 9,
                'lat' => 36.856586,
                'lng' => 47.616062,
                'boundary_id' => null
            ],
            [
                'id' => 289,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت پشتوک',
                'unit_type_id' => 9,
                'lat' => 36.699413,
                'lng' => 47.649568,
                'boundary_id' => null
            ],
            [
                'id' => 290,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت وهران',
                'unit_type_id' => 9,
                'lat' => 36.698263,
                'lng' => 47.83026,
                'boundary_id' => null
            ],
            [
                'id' => 291,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت مادآباد',
                'unit_type_id' => 9,
                'lat' => 36.710134,
                'lng' => 47.815754,
                'boundary_id' => null
            ],
            [
                'id' => 292,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه آقجه قلعه زنجان',
                'unit_type_id' => 9,
                'lat' => 37.112636,
                'lng' => 48.183526,
                'boundary_id' => null
            ],
            [
                'id' => 293,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه بهرام بيگ زنجان',
                'unit_type_id' => 9,
                'lat' => 37.059028,
                'lng' => 48.249545,
                'boundary_id' => null
            ],
            [
                'id' => 294,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه بيرونده زنجان',
                'unit_type_id' => 9,
                'lat' => 37.164498,
                'lng' => 48.14527,
                'boundary_id' => null
            ],
            [
                'id' => 295,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه تکمه داش زنجان',
                'unit_type_id' => 9,
                'lat' => 37.059224,
                'lng' => 48.205807,
                'boundary_id' => null
            ],
            [
                'id' => 296,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه حاج سيران زنجان',
                'unit_type_id' => 9,
                'lat' => 37.182561,
                'lng' => 48.222715,
                'boundary_id' => null
            ],
            [
                'id' => 297,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه قبله بلاغي زنجان',
                'unit_type_id' => 9,
                'lat' => 37.115899,
                'lng' => 48.277096,
                'boundary_id' => null
            ],
            [
                'id' => 298,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت انگوران',
                'unit_type_id' => 9,
                'lat' => 36.579259,
                'lng' => 47.64812,
                'boundary_id' => null
            ],
            [
                'id' => 299,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه قندرقالو زنجان',
                'unit_type_id' => 9,
                'lat' => 37.13546,
                'lng' => 48.300292,
                'boundary_id' => null
            ],
            [
                'id' => 300,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت مغانلو',
                'unit_type_id' => 9,
                'lat' => 36.474396,
                'lng' => 47.732567,
                'boundary_id' => null
            ],
            [
                'id' => 301,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت قواق سفلی',
                'unit_type_id' => 9,
                'lat' => 36.444871,
                'lng' => 47.598431,
                'boundary_id' => null
            ],
            [
                'id' => 302,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت ایالو',
                'unit_type_id' => 9,
                'lat' => 36.479736,
                'lng' => 47.680736,
                'boundary_id' => null
            ],
            [
                'id' => 303,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت قره دره',
                'unit_type_id' => 9,
                'lat' => 36.508182,
                'lng' => 47.715797,
                'boundary_id' => null
            ],
            [
                'id' => 304,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت کپز',
                'unit_type_id' => 9,
                'lat' => 36.585783,
                'lng' => 47.564185,
                'boundary_id' => null
            ],
            [
                'id' => 305,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه ورمزيار عليا زنجان',
                'unit_type_id' => 9,
                'lat' => 37.158135,
                'lng' => 48.259666,
                'boundary_id' => null
            ],
            [
                'id' => 306,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه بوغداکندي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.574232,
                'lng' => 48.146648,
                'boundary_id' => null
            ],
            [
                'id' => 307,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه تلخاب زنجان',
                'unit_type_id' => 9,
                'lat' => 36.752237,
                'lng' => 48.096839,
                'boundary_id' => null
            ],
            [
                'id' => 308,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه دهشير عليا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.567109,
                'lng' => 48.083926,
                'boundary_id' => null
            ],
            [
                'id' => 309,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه قوزلو زنجان',
                'unit_type_id' => 9,
                'lat' => 36.50317,
                'lng' => 47.992827,
                'boundary_id' => null
            ],
            [
                'id' => 310,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه مرصع زنجان',
                'unit_type_id' => 9,
                'lat' => 36.607372,
                'lng' => 48.024142,
                'boundary_id' => null
            ],
            [
                'id' => 311,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه چايرلو زنجان',
                'unit_type_id' => 9,
                'lat' => 36.532874,
                'lng' => 48.004389,
                'boundary_id' => null
            ],
            [
                'id' => 312,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه کاوند زنجان',
                'unit_type_id' => 9,
                'lat' => 36.609782,
                'lng' => 48.150582,
                'boundary_id' => null
            ],
            [
                'id' => 313,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه گل تپه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.586331,
                'lng' => 48.107245,
                'boundary_id' => null
            ],
            [
                'id' => 314,
                'region_id' => 2,
                'parent_id' => 34,
                'name' => 'خانه تهم زنجان',
                'unit_type_id' => 9,
                'lat' => 36.792266,
                'lng' => 48.567854,
                'boundary_id' => null
            ],
            [
                'id' => 315,
                'region_id' => 7,
                'parent_id' => 35,
                'name' => 'خانه بهداشت میانج',
                'unit_type_id' => 9,
                'lat' => 36.52921,
                'lng' => 47.481632,
                'boundary_id' => null
            ],
            [
                'id' => 316,
                'region_id' => 7,
                'parent_id' => 35,
                'name' => 'خانه بهداشت حلب سفلی',
                'unit_type_id' => 9,
                'lat' => 36.488707,
                'lng' => 47.472296,
                'boundary_id' => null
            ],
            [
                'id' => 317,
                'region_id' => 7,
                'parent_id' => 35,
                'name' => 'خانه بهداشت کوسج علیا',
                'unit_type_id' => 9,
                'lat' => 36.487994,
                'lng' => 47.50524,
                'boundary_id' => null
            ],
            [
                'id' => 318,
                'region_id' => 7,
                'parent_id' => 35,
                'name' => 'خانه بهداشت شیخلر',
                'unit_type_id' => 9,
                'lat' => 36.59786,
                'lng' => 47.485156,
                'boundary_id' => null
            ],
            [
                'id' => 319,
                'region_id' => 2,
                'parent_id' => 34,
                'name' => 'خانه چلگان زنجان',
                'unit_type_id' => 9,
                'lat' => 36.784126,
                'lng' => 48.663031,
                'boundary_id' => null
            ],
            [
                'id' => 320,
                'region_id' => 2,
                'parent_id' => 34,
                'name' => 'خانه گلهرود زنجان',
                'unit_type_id' => 9,
                'lat' => 36.815673,
                'lng' => 48.504045,
                'boundary_id' => null
            ],
            [
                'id' => 321,
                'region_id' => 2,
                'parent_id' => 38,
                'name' => 'خانه آقبلاغ زنجان',
                'unit_type_id' => 9,
                'lat' => 36.696941,
                'lng' => 48.06149,
                'boundary_id' => null
            ],
            [
                'id' => 322,
                'region_id' => 2,
                'parent_id' => 38,
                'name' => 'خانه حاج آرش زنجان',
                'unit_type_id' => 9,
                'lat' => 36.684039,
                'lng' => 48.160234,
                'boundary_id' => null
            ],
            [
                'id' => 323,
                'region_id' => 2,
                'parent_id' => 38,
                'name' => 'خانه ملالر زنجان',
                'unit_type_id' => 9,
                'lat' => 36.701612,
                'lng' => 48.226331,
                'boundary_id' => null
            ],
            [
                'id' => 324,
                'region_id' => 2,
                'parent_id' => 38,
                'name' => 'خانه ميرجان زنجان',
                'unit_type_id' => 9,
                'lat' => 36.742755,
                'lng' => 48.001077,
                'boundary_id' => null
            ],
            [
                'id' => 325,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت قرقان سفلی',
                'unit_type_id' => 9,
                'lat' => 36.444557,
                'lng' => 47.598993,
                'boundary_id' => null
            ],
            [
                'id' => 326,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه انجمن سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 37.000701,
                'lng' => 47.573806,
                'boundary_id' => null
            ],
            [
                'id' => 327,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه حصار زنجان',
                'unit_type_id' => 9,
                'lat' => 36.99026,
                'lng' => 47.718679,
                'boundary_id' => null
            ],
            [
                'id' => 328,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه رضا آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.873186,
                'lng' => 47.801607,
                'boundary_id' => null
            ],
            [
                'id' => 329,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'مهستان',
                'unit_type_id' => 9,
                'lat' => 36.342251,
                'lng' => 49.141278,
                'boundary_id' => null
            ],
            [
                'id' => 330,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه ساري کندکابلي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.976172,
                'lng' => 47.542725,
                'boundary_id' => null
            ],
            [
                'id' => 331,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه قره آغاج سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.958419,
                'lng' => 47.513116,
                'boundary_id' => null
            ],
            [
                'id' => 332,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه مشمپا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.943241,
                'lng' => 47.674917,
                'boundary_id' => null
            ],
            [
                'id' => 333,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه مهر آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.894848,
                'lng' => 47.928757,
                'boundary_id' => null
            ],
            [
                'id' => 334,
                'region_id' => 2,
                'parent_id' => 39,
                'name' => 'خانه چهر آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.877905,
                'lng' => 47.871307,
                'boundary_id' => null
            ],
            [
                'id' => 335,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => ' پیرسقا',
                'unit_type_id' => 9,
                'lat' => 36.332097,
                'lng' => 49.014453,
                'boundary_id' => null
            ],
            [
                'id' => 336,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => ' خراسانلو',
                'unit_type_id' => 9,
                'lat' => 36.366121,
                'lng' => 49.092148,
                'boundary_id' => null
            ],
            [
                'id' => 337,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'کوه زین',
                'unit_type_id' => 9,
                'lat' => 36.385005,
                'lng' => 49.075374,
                'boundary_id' => null
            ],
            [
                'id' => 338,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'داشبلاغ',
                'unit_type_id' => 9,
                'lat' => 36.284722,
                'lng' => 49.009528,
                'boundary_id' => null
            ],
            [
                'id' => 339,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه امام زنجان',
                'unit_type_id' => 9,
                'lat' => 36.702343,
                'lng' => 48.806826,
                'boundary_id' => null
            ],
            [
                'id' => 340,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه بناب زنجان',
                'unit_type_id' => 9,
                'lat' => 36.58429,
                'lng' => 48.681941,
                'boundary_id' => null
            ],
            [
                'id' => 341,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه بولاماجي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.562131,
                'lng' => 48.626847,
                'boundary_id' => null
            ],
            [
                'id' => 342,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه ديزج آباد 1 زنجان',
                'unit_type_id' => 9,
                'lat' => 36.630528,
                'lng' => 48.600056,
                'boundary_id' => null
            ],
            [
                'id' => 343,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'سروان جهان',
                'unit_type_id' => 9,
                'lat' => 36.263934,
                'lng' => 48.972281,
                'boundary_id' => null
            ],
            [
                'id' => 344,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'کبودچشمه',
                'unit_type_id' => 9,
                'lat' => 36.255001,
                'lng' => 49.033389,
                'boundary_id' => null
            ],
            [
                'id' => 345,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه ديزج آباد 2 زنجان',
                'unit_type_id' => 9,
                'lat' => 36.630528,
                'lng' => 48.600056,
                'boundary_id' => null
            ],
            [
                'id' => 346,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه ذاکر زنجان',
                'unit_type_id' => 9,
                'lat' => 36.645739,
                'lng' => 48.719904,
                'boundary_id' => null
            ],
            [
                'id' => 347,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه زرنان زنجان',
                'unit_type_id' => 9,
                'lat' => 36.682755,
                'lng' => 48.621481,
                'boundary_id' => null
            ],
            [
                'id' => 348,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه سولي چاي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.730927,
                'lng' => 48.788226,
                'boundary_id' => null
            ],
            [
                'id' => 349,
                'region_id' => 3,
                'parent_id' => 202,
                'name' => 'ارهان',
                'unit_type_id' => 9,
                'lat' => 36.314833,
                'lng' => 48.912722,
                'boundary_id' => null
            ],
            [
                'id' => 350,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه مرواريد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.611591,
                'lng' => 48.793596,
                'boundary_id' => null
            ],
            [
                'id' => 351,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه نيماور زنجان',
                'unit_type_id' => 9,
                'lat' => 36.554277,
                'lng' => 48.679098,
                'boundary_id' => null
            ],
            [
                'id' => 352,
                'region_id' => 3,
                'parent_id' => 202,
                'name' => 'الگزیر',
                'unit_type_id' => 9,
                'lat' => 36.300728,
                'lng' => 48.942905,
                'boundary_id' => null
            ],
            [
                'id' => 353,
                'region_id' => 3,
                'parent_id' => 41,
                'name' => 'عمید آباد',
                'unit_type_id' => 9,
                'lat' => 36.369281,
                'lng' => 48.960961,
                'boundary_id' => null
            ],
            [
                'id' => 354,
                'region_id' => 3,
                'parent_id' => 41,
                'name' => 'جداقیه',
                'unit_type_id' => 9,
                'lat' => 36.447001,
                'lng' => 48.996251,
                'boundary_id' => null
            ],
            [
                'id' => 355,
                'region_id' => 3,
                'parent_id' => 41,
                'name' => 'چرگر',
                'unit_type_id' => 9,
                'lat' => 36.421944,
                'lng' => 49.048528,
                'boundary_id' => null
            ],
            [
                'id' => 356,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه آقکند زنجان',
                'unit_type_id' => 9,
                'lat' => 36.572168,
                'lng' => 48.270711,
                'boundary_id' => null
            ],
            [
                'id' => 357,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه اژدهاتو زنجان',
                'unit_type_id' => 9,
                'lat' => 36.602523,
                'lng' => 48.411637,
                'boundary_id' => null
            ],
            [
                'id' => 358,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه باروت آغاجي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.566891,
                'lng' => 48.32564,
                'boundary_id' => null
            ],
            [
                'id' => 359,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه رازبين زنجان',
                'unit_type_id' => 9,
                'lat' => 36.625881,
                'lng' => 48.406405,
                'boundary_id' => null
            ],
            [
                'id' => 360,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه ريحان زنجان',
                'unit_type_id' => 9,
                'lat' => 36.540665,
                'lng' => 48.394379,
                'boundary_id' => null
            ],
            [
                'id' => 361,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه پاپائي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.53548,
                'lng' => 48.363673,
                'boundary_id' => null
            ],
            [
                'id' => 362,
                'region_id' => 2,
                'parent_id' => 43,
                'name' => 'خانه چورزق زنجان',
                'unit_type_id' => 9,
                'lat' => 36.601142,
                'lng' => 48.335212,
                'boundary_id' => null
            ],
            [
                'id' => 363,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه رجعين زنجان',
                'unit_type_id' => 9,
                'lat' => 37.135331,
                'lng' => 47.827616,
                'boundary_id' => null
            ],
            [
                'id' => 364,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه سرچم سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 37.122142,
                'lng' => 47.910491,
                'boundary_id' => null
            ],
            [
                'id' => 365,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه سلطان آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 37.079901,
                'lng' => 47.937643,
                'boundary_id' => null
            ],
            [
                'id' => 366,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه پيله خاصه زنجان',
                'unit_type_id' => 9,
                'lat' => 37.072012,
                'lng' => 47.952754,
                'boundary_id' => null
            ],
            [
                'id' => 367,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه چوروک سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 37.119228,
                'lng' => 47.874819,
                'boundary_id' => null
            ],
            [
                'id' => 368,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه چپ چپ زنجان',
                'unit_type_id' => 9,
                'lat' => 37.016999,
                'lng' => 47.952754,
                'boundary_id' => null
            ],
            [
                'id' => 369,
                'region_id' => 2,
                'parent_id' => 44,
                'name' => 'خانه گمش آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.999815,
                'lng' => 47.986821,
                'boundary_id' => null
            ],
            [
                'id' => 370,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 'خانه سهرين 1 زنجان',
                'unit_type_id' => 9,
                'lat' => 36.866606,
                'lng' => 48.418616,
                'boundary_id' => null
            ],
            [
                'id' => 371,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 'خانه سهرين 2 زنجان',
                'unit_type_id' => 9,
                'lat' => 36.86369,
                'lng' => 48.413218,
                'boundary_id' => null
            ],
            [
                'id' => 372,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 'خانه قشلاق زنجان',
                'unit_type_id' => 9,
                'lat' => 36.919054,
                'lng' => 48.322078,
                'boundary_id' => null
            ],
            [
                'id' => 373,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 'خانه وننق زنجان',
                'unit_type_id' => 9,
                'lat' => 36.934702,
                'lng' => 48.409332,
                'boundary_id' => null
            ],
            [
                'id' => 374,
                'region_id' => 2,
                'parent_id' => 46,
                'name' => 'خانه قره بوطه زنجان',
                'unit_type_id' => 9,
                'lat' => 37.060769,
                'lng' => 47.768352,
                'boundary_id' => null
            ],
            [
                'id' => 375,
                'region_id' => 2,
                'parent_id' => 46,
                'name' => 'خانه قيطول زنجان',
                'unit_type_id' => 9,
                'lat' => 37.109681,
                'lng' => 47.791665,
                'boundary_id' => null
            ],
            [
                'id' => 376,
                'region_id' => 2,
                'parent_id' => 46,
                'name' => 'خانه گرگ تپه زنجان',
                'unit_type_id' => 9,
                'lat' => 37.006215,
                'lng' => 47.834242,
                'boundary_id' => null
            ],
            [
                'id' => 377,
                'region_id' => 2,
                'parent_id' => 50,
                'name' => 'خانه خاتون کندي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.341678,
                'lng' => 47.985535,
                'boundary_id' => null
            ],
            [
                'id' => 378,
                'region_id' => 2,
                'parent_id' => 50,
                'name' => 'خانه قره گوزلو زنجان',
                'unit_type_id' => 9,
                'lat' => 36.740994,
                'lng' => 48.042196,
                'boundary_id' => null
            ],
            [
                'id' => 379,
                'region_id' => 2,
                'parent_id' => 50,
                'name' => 'خانه قلتوق زنجان',
                'unit_type_id' => 9,
                'lat' => 36.495777,
                'lng' => 48.070562,
                'boundary_id' => null
            ],
            [
                'id' => 380,
                'region_id' => 2,
                'parent_id' => 50,
                'name' => 'خانه گوگجه قيا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.516311,
                'lng' => 48.108566,
                'boundary_id' => null
            ],
            [
                'id' => 381,
                'region_id' => 2,
                'parent_id' => 51,
                'name' => 'خانه حبش زنجان',
                'unit_type_id' => 9,
                'lat' => 36.769279,
                'lng' => 47.78319,
                'boundary_id' => null
            ],
            [
                'id' => 382,
                'region_id' => 2,
                'parent_id' => 51,
                'name' => 'خانه علي آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.725243,
                'lng' => 48.334648,
                'boundary_id' => null
            ],
            [
                'id' => 383,
                'region_id' => 2,
                'parent_id' => 51,
                'name' => 'خانه لولک آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.744621,
                'lng' => 47.862239,
                'boundary_id' => null
            ],
            [
                'id' => 384,
                'region_id' => 2,
                'parent_id' => 51,
                'name' => 'خانه ملک باغي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.731158,
                'lng' => 47.848042,
                'boundary_id' => null
            ],
            [
                'id' => 385,
                'region_id' => 2,
                'parent_id' => 51,
                'name' => 'خانه مياندره زنجان',
                'unit_type_id' => 9,
                'lat' => 36.807523,
                'lng' => 47.738242,
                'boundary_id' => null
            ],
            [
                'id' => 386,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه دره ليک زنجان',
                'unit_type_id' => 9,
                'lat' => 36.838604,
                'lng' => 48.154696,
                'boundary_id' => null
            ],
            [
                'id' => 387,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه دولاناب زنجان',
                'unit_type_id' => 9,
                'lat' => 36.89629,
                'lng' => 48.131995,
                'boundary_id' => null
            ],
            [
                'id' => 388,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه سردهات جعفري زنجان',
                'unit_type_id' => 9,
                'lat' => 36.96466,
                'lng' => 48.035899,
                'boundary_id' => null
            ],
            [
                'id' => 389,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه سردهات شيخ زنجان',
                'unit_type_id' => 9,
                'lat' => 37.013089,
                'lng' => 48.096416,
                'boundary_id' => null
            ],
            [
                'id' => 390,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه سيف آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.960803,
                'lng' => 48.130719,
                'boundary_id' => null
            ],
            [
                'id' => 391,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه نيک پي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.849508,
                'lng' => 48.175863,
                'boundary_id' => null
            ],
            [
                'id' => 392,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه کزبر زنجان',
                'unit_type_id' => 9,
                'lat' => 36.82432,
                'lng' => 48.224604,
                'boundary_id' => null
            ],
            [
                'id' => 393,
                'region_id' => 2,
                'parent_id' => 52,
                'name' => 'خانه کناوند زنجان',
                'unit_type_id' => 9,
                'lat' => 36.804208,
                'lng' => 48.234111,
                'boundary_id' => null
            ],
            [
                'id' => 394,
                'region_id' => 2,
                'parent_id' => 53,
                'name' => 'خانه اسفجين زنجان',
                'unit_type_id' => 9,
                'lat' => 36.744863,
                'lng' => 48.247967,
                'boundary_id' => null
            ],
            [
                'id' => 395,
                'region_id' => 2,
                'parent_id' => 53,
                'name' => 'خانه امين آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.732416,
                'lng' => 48.281089,
                'boundary_id' => null
            ],
            [
                'id' => 396,
                'region_id' => 2,
                'parent_id' => 53,
                'name' => 'خانه ينگجه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.77784,
                'lng' => 48.277353,
                'boundary_id' => null
            ],
            [
                'id' => 397,
                'region_id' => 2,
                'parent_id' => 53,
                'name' => 'خانه چير زنجان',
                'unit_type_id' => 9,
                'lat' => 36.727063,
                'lng' => 48.307556,
                'boundary_id' => null
            ],
            [
                'id' => 398,
                'region_id' => 2,
                'parent_id' => 54,
                'name' => 'خانه اسفناج زنجان',
                'unit_type_id' => 9,
                'lat' => 36.815641,
                'lng' => 48.180276,
                'boundary_id' => null
            ],
            [
                'id' => 399,
                'region_id' => 2,
                'parent_id' => 54,
                'name' => 'خانه قره آغاج زنجان',
                'unit_type_id' => 9,
                'lat' => 36.769818,
                'lng' => 48.095994,
                'boundary_id' => null
            ],
            [
                'id' => 400,
                'region_id' => 2,
                'parent_id' => 54,
                'name' => 'خانه قزل تپه عليقلي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.725173,
                'lng' => 48.138158,
                'boundary_id' => null
            ],
            [
                'id' => 401,
                'region_id' => 2,
                'parent_id' => 54,
                'name' => 'خانه يامچي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.779324,
                'lng' => 48.215523,
                'boundary_id' => null
            ],
            [
                'id' => 402,
                'region_id' => 2,
                'parent_id' => 55,
                'name' => 'خانه اندآباد سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.807289,
                'lng' => 47.989782,
                'boundary_id' => null
            ],
            [
                'id' => 403,
                'region_id' => 2,
                'parent_id' => 55,
                'name' => 'خانه اندآباد عليا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.847084,
                'lng' => 47.982659,
                'boundary_id' => null
            ],
            [
                'id' => 404,
                'region_id' => 2,
                'parent_id' => 55,
                'name' => 'خانه بزوشا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.791563,
                'lng' => 48.067685,
                'boundary_id' => null
            ],
            [
                'id' => 405,
                'region_id' => 2,
                'parent_id' => 55,
                'name' => 'خانه قره کل زنجان',
                'unit_type_id' => 9,
                'lat' => 36.778344,
                'lng' => 48.033609,
                'boundary_id' => null
            ],
            [
                'id' => 406,
                'region_id' => 2,
                'parent_id' => 55,
                'name' => 'خانه نصير آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.759691,
                'lng' => 47.934285,
                'boundary_id' => null
            ],
            [
                'id' => 407,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت خانکندی',
                'unit_type_id' => 9,
                'lat' => 36.536671,
                'lng' => 47.804119,
                'boundary_id' => null
            ],
            [
                'id' => 408,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت تاتارده',
                'unit_type_id' => 9,
                'lat' => 35.956306,
                'lng' => 48.104944,
                'boundary_id' => null
            ],
            [
                'id' => 409,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت گوگرچینک',
                'unit_type_id' => 9,
                'lat' => 35.887639,
                'lng' => 48.115472,
                'boundary_id' => null
            ],
            [
                'id' => 410,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت فریدون',
                'unit_type_id' => 9,
                'lat' => 35.907972,
                'lng' => 48.075389,
                'boundary_id' => null
            ],
            [
                'id' => 411,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت اولی بیک',
                'unit_type_id' => 9,
                'lat' => 35.940167,
                'lng' => 48.143556,
                'boundary_id' => null
            ],
            [
                'id' => 412,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت قویجوق',
                'unit_type_id' => 9,
                'lat' => 35.931694,
                'lng' => 47.889722,
                'boundary_id' => null
            ],
            [
                'id' => 413,
                'region_id' => 5,
                'parent_id' => 70,
                'name' => 'خانه بهداشت حسنلو',
                'unit_type_id' => 9,
                'lat' => 35.954778,
                'lng' => 47.989944,
                'boundary_id' => null
            ],
            [
                'id' => 414,
                'region_id' => 5,
                'parent_id' => 75,
                'name' => 'خانه بهداشت آقبلاغ',
                'unit_type_id' => 9,
                'lat' => 36.162361,
                'lng' => 48.371556,
                'boundary_id' => null
            ],
            [
                'id' => 415,
                'region_id' => 5,
                'parent_id' => 75,
                'name' => 'خانه بهداشت نصرت آباد',
                'unit_type_id' => 9,
                'lat' => 36.209139,
                'lng' => 48.380028,
                'boundary_id' => null
            ],
            [
                'id' => 416,
                'region_id' => 5,
                'parent_id' => 75,
                'name' => 'خانه بهداشت سرین دره',
                'unit_type_id' => 9,
                'lat' => 36.187417,
                'lng' => 48.359389,
                'boundary_id' => null
            ],
            [
                'id' => 417,
                'region_id' => 5,
                'parent_id' => 75,
                'name' => 'خانه بهداشت گوران',
                'unit_type_id' => 9,
                'lat' => 36.188306,
                'lng' => 48.315222,
                'boundary_id' => null
            ],
            [
                'id' => 418,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت باشقشلاق',
                'unit_type_id' => 9,
                'lat' => 36.046306,
                'lng' => 48.079778,
                'boundary_id' => null
            ],
            [
                'id' => 419,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت توتورقان',
                'unit_type_id' => 9,
                'lat' => 36.007833,
                'lng' => 48.003139,
                'boundary_id' => null
            ],
            [
                'id' => 420,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت منداق',
                'unit_type_id' => 9,
                'lat' => 36.064111,
                'lng' => 48.039306,
                'boundary_id' => null
            ],
            [
                'id' => 421,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت خلیفه لو',
                'unit_type_id' => 9,
                'lat' => 35.994083,
                'lng' => 47.992889,
                'boundary_id' => null
            ],
            [
                'id' => 422,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت جمعه لو',
                'unit_type_id' => 9,
                'lat' => 36.057278,
                'lng' => 48.1815,
                'boundary_id' => null
            ],
            [
                'id' => 423,
                'region_id' => 5,
                'parent_id' => 76,
                'name' => 'خانه بهداشت آقاچقلو',
                'unit_type_id' => 9,
                'lat' => 36.115667,
                'lng' => 48.004861,
                'boundary_id' => null
            ],
            [
                'id' => 424,
                'region_id' => 5,
                'parent_id' => 77,
                'name' => 'خانه بهداشت بولاماجی',
                'unit_type_id' => 9,
                'lat' => 36.350492,
                'lng' => 48.587605,
                'boundary_id' => null
            ],
            [
                'id' => 425,
                'region_id' => 5,
                'parent_id' => 77,
                'name' => 'خانه بهداشت ینگجه',
                'unit_type_id' => 9,
                'lat' => 36.346778,
                'lng' => 48.641694,
                'boundary_id' => null
            ],
            [
                'id' => 426,
                'region_id' => 5,
                'parent_id' => 77,
                'name' => 'خانه بهداشت چنگوری',
                'unit_type_id' => 9,
                'lat' => 36.390528,
                'lng' => 48.595806,
                'boundary_id' => null
            ],
            [
                'id' => 427,
                'region_id' => 5,
                'parent_id' => 77,
                'name' => 'خانه بهداشت چیلاخور',
                'unit_type_id' => 9,
                'lat' => 36.383028,
                'lng' => 48.573028,
                'boundary_id' => null
            ],
            [
                'id' => 428,
                'region_id' => 5,
                'parent_id' => 77,
                'name' => 'خانه بهداشت دابانلو',
                'unit_type_id' => 9,
                'lat' => 36.396833,
                'lng' => 48.564556,
                'boundary_id' => null
            ],
            [
                'id' => 429,
                'region_id' => 5,
                'parent_id' => 79,
                'name' => 'خانه بهداشت برون',
                'unit_type_id' => 9,
                'lat' => 35.930167,
                'lng' => 48.365833,
                'boundary_id' => null
            ],
            [
                'id' => 430,
                'region_id' => 5,
                'parent_id' => 79,
                'name' => 'خانه بهداشت گل تپه',
                'unit_type_id' => 9,
                'lat' => 35.861333,
                'lng' => 48.47825,
                'boundary_id' => null
            ],
            [
                'id' => 431,
                'region_id' => 5,
                'parent_id' => 79,
                'name' => 'خانه بهداشت حسام آباد',
                'unit_type_id' => 9,
                'lat' => 35.874385,
                'lng' => 48.41162,
                'boundary_id' => null
            ],
            [
                'id' => 432,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت آبی سفلی',
                'unit_type_id' => 9,
                'lat' => 36.029944,
                'lng' => 48.605028,
                'boundary_id' => null
            ],
            [
                'id' => 433,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت قینرجه',
                'unit_type_id' => 9,
                'lat' => 36.045528,
                'lng' => 48.624167,
                'boundary_id' => null
            ],
            [
                'id' => 434,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت ترپاخلو',
                'unit_type_id' => 9,
                'lat' => 35.970306,
                'lng' => 48.601778,
                'boundary_id' => null
            ],
            [
                'id' => 435,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت حصار',
                'unit_type_id' => 9,
                'lat' => 36.062139,
                'lng' => 48.58225,
                'boundary_id' => null
            ],
            [
                'id' => 436,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت عینجک',
                'unit_type_id' => 9,
                'lat' => 36.019778,
                'lng' => 48.532194,
                'boundary_id' => null
            ],
            [
                'id' => 437,
                'region_id' => 5,
                'parent_id' => 80,
                'name' => 'خانه بهداشت قره محمد',
                'unit_type_id' => 9,
                'lat' => 36.065444,
                'lng' => 48.556222,
                'boundary_id' => null
            ],
            [
                'id' => 438,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت دوتپه 1',
                'unit_type_id' => 9,
                'lat' => 36.129556,
                'lng' => 48.83275,
                'boundary_id' => null
            ],
            [
                'id' => 439,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت دوتپه 2',
                'unit_type_id' => 9,
                'lat' => 36.13,
                'lng' => 48.829139,
                'boundary_id' => null
            ],
            [
                'id' => 440,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت دهشیر',
                'unit_type_id' => 9,
                'lat' => 36.0865,
                'lng' => 48.834694,
                'boundary_id' => null
            ],
            [
                'id' => 441,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت پرچین',
                'unit_type_id' => 9,
                'lat' => 36.059806,
                'lng' => 48.835278,
                'boundary_id' => null
            ],
            [
                'id' => 442,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت چپقلو',
                'unit_type_id' => 9,
                'lat' => 36.191056,
                'lng' => 48.898944,
                'boundary_id' => null
            ],
            [
                'id' => 443,
                'region_id' => 5,
                'parent_id' => 81,
                'name' => 'خانه بهداشت آقچه قیا',
                'unit_type_id' => 9,
                'lat' => 36.058944,
                'lng' => 48.923583,
                'boundary_id' => null
            ],
            [
                'id' => 444,
                'region_id' => 5,
                'parent_id' => 82,
                'name' => 'خانه بهداشت سقرچین',
                'unit_type_id' => 9,
                'lat' => 35.856083,
                'lng' => 48.612083,
                'boundary_id' => null
            ],
            [
                'id' => 445,
                'region_id' => 5,
                'parent_id' => 82,
                'name' => 'خانه بهداشت شعبان',
                'unit_type_id' => 9,
                'lat' => 35.909417,
                'lng' => 48.635222,
                'boundary_id' => null
            ],
            [
                'id' => 446,
                'region_id' => 5,
                'parent_id' => 82,
                'name' => 'خانه بهداشت قجور',
                'unit_type_id' => 9,
                'lat' => 35.807389,
                'lng' => 48.564306,
                'boundary_id' => null
            ],
            [
                'id' => 447,
                'region_id' => 5,
                'parent_id' => 82,
                'name' => 'خانه بهداشت محمدخلج',
                'unit_type_id' => 9,
                'lat' => 35.757889,
                'lng' => 48.606194,
                'boundary_id' => null
            ],
            [
                'id' => 448,
                'region_id' => 5,
                'parent_id' => 82,
                'name' => 'خانه بهداشت زاغج',
                'unit_type_id' => 9,
                'lat' => 35.814083,
                'lng' => 48.585583,
                'boundary_id' => null
            ],
            [
                'id' => 449,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت اردهین',
                'unit_type_id' => 9,
                'lat' => 35.654417,
                'lng' => 48.389056,
                'boundary_id' => null
            ],
            [
                'id' => 450,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت امیرلو',
                'unit_type_id' => 9,
                'lat' => 35.765778,
                'lng' => 48.370278,
                'boundary_id' => null
            ],
            [
                'id' => 451,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت داشبلاغ',
                'unit_type_id' => 9,
                'lat' => 35.684583,
                'lng' => 48.483944,
                'boundary_id' => null
            ],
            [
                'id' => 452,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت سراب',
                'unit_type_id' => 9,
                'lat' => 35.704278,
                'lng' => 48.320056,
                'boundary_id' => null
            ],
            [
                'id' => 453,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت قیاسکندی',
                'unit_type_id' => 9,
                'lat' => 35.698139,
                'lng' => 48.432472,
                'boundary_id' => null
            ],
            [
                'id' => 454,
                'region_id' => 5,
                'parent_id' => 83,
                'name' => 'خانه بهداشت قره کهریز',
                'unit_type_id' => 9,
                'lat' => 35.737833,
                'lng' => 48.405694,
                'boundary_id' => null
            ],
            [
                'id' => 455,
                'region_id' => 5,
                'parent_id' => 84,
                'name' => 'خانه بهداشت پابند',
                'unit_type_id' => 9,
                'lat' => 36.267167,
                'lng' => 48.773833,
                'boundary_id' => null
            ],
            [
                'id' => 456,
                'region_id' => 5,
                'parent_id' => 84,
                'name' => 'خانه بهداشت دهجلال',
                'unit_type_id' => 9,
                'lat' => 36.319833,
                'lng' => 48.702222,
                'boundary_id' => null
            ],
            [
                'id' => 457,
                'region_id' => 5,
                'parent_id' => 84,
                'name' => 'خانه بهداشت داشلوجه',
                'unit_type_id' => 9,
                'lat' => 36.222694,
                'lng' => 48.826222,
                'boundary_id' => null
            ],
            [
                'id' => 458,
                'region_id' => 5,
                'parent_id' => 84,
                'name' => 'خانه بهداشت مزیدآباد',
                'unit_type_id' => 9,
                'lat' => 36.202528,
                'lng' => 48.603028,
                'boundary_id' => null
            ],
            [
                'id' => 459,
                'region_id' => 5,
                'parent_id' => 84,
                'name' => 'خانه بهداشت کشک آباد',
                'unit_type_id' => 9,
                'lat' => 36.293833,
                'lng' => 48.755361,
                'boundary_id' => null
            ],
            [
                'id' => 460,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت محمودآباد',
                'unit_type_id' => 9,
                'lat' => 36.007778,
                'lng' => 48.681639,
                'boundary_id' => null
            ],
            [
                'id' => 461,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت خالق آباد',
                'unit_type_id' => 9,
                'lat' => 36.045028,
                'lng' => 48.704417,
                'boundary_id' => null
            ],
            [
                'id' => 462,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت اینچه',
                'unit_type_id' => 9,
                'lat' => 36.014722,
                'lng' => 48.652,
                'boundary_id' => null
            ],
            [
                'id' => 463,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت قوشه کند',
                'unit_type_id' => 9,
                'lat' => 36.073278,
                'lng' => 48.702111,
                'boundary_id' => null
            ],
            [
                'id' => 464,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت آهارمشکین',
                'unit_type_id' => 9,
                'lat' => 36.06775,
                'lng' => 48.726944,
                'boundary_id' => null
            ],
            [
                'id' => 465,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت کوچ تپه',
                'unit_type_id' => 9,
                'lat' => 36.000222,
                'lng' => 48.726667,
                'boundary_id' => null
            ],
            [
                'id' => 466,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت علی آباد',
                'unit_type_id' => 9,
                'lat' => 36.018417,
                'lng' => 48.730444,
                'boundary_id' => null
            ],
            [
                'id' => 467,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت کنگک',
                'unit_type_id' => 9,
                'lat' => 35.959472,
                'lng' => 48.707972,
                'boundary_id' => null
            ],
            [
                'id' => 468,
                'region_id' => 5,
                'parent_id' => 85,
                'name' => 'خانه بهداشت زرین گل',
                'unit_type_id' => 9,
                'lat' => 36.000639,
                'lng' => 48.696194,
                'boundary_id' => null
            ],
            [
                'id' => 469,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت نعلبندان',
                'unit_type_id' => 9,
                'lat' => 36.130833,
                'lng' => 48.665083,
                'boundary_id' => null
            ],
            [
                'id' => 470,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت ورجوشان',
                'unit_type_id' => 9,
                'lat' => 36.102,
                'lng' => 48.704306,
                'boundary_id' => null
            ],
            [
                'id' => 471,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت بیجقین',
                'unit_type_id' => 9,
                'lat' => 36.110722,
                'lng' => 48.704083,
                'boundary_id' => null
            ],
            [
                'id' => 472,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت توپقره',
                'unit_type_id' => 9,
                'lat' => 36.134167,
                'lng' => 48.753944,
                'boundary_id' => null
            ],
            [
                'id' => 473,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت بلگشیر',
                'unit_type_id' => 9,
                'lat' => 36.120333,
                'lng' => 48.765417,
                'boundary_id' => null
            ],
            [
                'id' => 474,
                'region_id' => 5,
                'parent_id' => 86,
                'name' => 'خانه بهداشت شیخلو',
                'unit_type_id' => 9,
                'lat' => 36.131028,
                'lng' => 48.707889,
                'boundary_id' => null
            ],
            [
                'id' => 475,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'خانه نوربهار 1',
                'unit_type_id' => 9,
                'lat' => 35.936722,
                'lng' => 48.790583,
                'boundary_id' => null
            ],
            [
                'id' => 476,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'خانه بهداشت جرین',
                'unit_type_id' => 9,
                'lat' => 35.970861,
                'lng' => 48.806111,
                'boundary_id' => null
            ],
            [
                'id' => 477,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'خانه بهداشت کهل آباد',
                'unit_type_id' => 9,
                'lat' => 35.940194,
                'lng' => 48.745083,
                'boundary_id' => null
            ],
            [
                'id' => 478,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'خانه نوربهار 2',
                'unit_type_id' => 9,
                'lat' => 35.939083,
                'lng' => 48.795222,
                'boundary_id' => null
            ],
            [
                'id' => 479,
                'region_id' => 5,
                'parent_id' => 88,
                'name' => 'خانه بهداشت چوزوک',
                'unit_type_id' => 9,
                'lat' => 36.301556,
                'lng' => 48.434778,
                'boundary_id' => null
            ],
            [
                'id' => 480,
                'region_id' => 5,
                'parent_id' => 88,
                'name' => 'خانه بهداشت زرزر',
                'unit_type_id' => 9,
                'lat' => 36.289278,
                'lng' => 48.444528,
                'boundary_id' => null
            ],
            [
                'id' => 481,
                'region_id' => 5,
                'parent_id' => 88,
                'name' => 'خانه بهداشت آقاجری',
                'unit_type_id' => 9,
                'lat' => 36.311,
                'lng' => 48.416472,
                'boundary_id' => null
            ],
            [
                'id' => 482,
                'region_id' => 5,
                'parent_id' => 88,
                'name' => 'خانه بهداشت آلنجه',
                'unit_type_id' => 9,
                'lat' => 36.240639,
                'lng' => 48.394722,
                'boundary_id' => null
            ],
            [
                'id' => 483,
                'region_id' => 5,
                'parent_id' => 88,
                'name' => 'خانه بهداشت دولجین',
                'unit_type_id' => 9,
                'lat' => 36.277056,
                'lng' => 48.42125,
                'boundary_id' => null
            ],
            [
                'id' => 484,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت صالح آباد',
                'unit_type_id' => 9,
                'lat' => 35.999222,
                'lng' => 48.421611,
                'boundary_id' => null
            ],
            [
                'id' => 485,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت دلایرسفلی',
                'unit_type_id' => 9,
                'lat' => 35.985417,
                'lng' => 48.462833,
                'boundary_id' => null
            ],
            [
                'id' => 486,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت کسیک',
                'unit_type_id' => 9,
                'lat' => 35.953171,
                'lng' => 48.386943,
                'boundary_id' => null
            ],
            [
                'id' => 487,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت کرسف 1',
                'unit_type_id' => 9,
                'lat' => 36.043583,
                'lng' => 48.512444,
                'boundary_id' => null
            ],
            [
                'id' => 488,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت کرسف 2',
                'unit_type_id' => 9,
                'lat' => 36.043361,
                'lng' => 48.512306,
                'boundary_id' => null
            ],
            [
                'id' => 489,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت قلعه جوق',
                'unit_type_id' => 9,
                'lat' => 36.025361,
                'lng' => 48.347944,
                'boundary_id' => null
            ],
            [
                'id' => 490,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت یاراحمدلو',
                'unit_type_id' => 9,
                'lat' => 35.9955,
                'lng' => 48.309194,
                'boundary_id' => null
            ],
            [
                'id' => 491,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت ابراهیم آباد',
                'unit_type_id' => 9,
                'lat' => 36.054972,
                'lng' => 48.240611,
                'boundary_id' => null
            ],
            [
                'id' => 492,
                'region_id' => 5,
                'parent_id' => 89,
                'name' => 'خانه بهداشت ابداللر',
                'unit_type_id' => 9,
                'lat' => 36.102083,
                'lng' => 48.357083,
                'boundary_id' => null
            ],
            [
                'id' => 493,
                'region_id' => 5,
                'parent_id' => 90,
                'name' => 'خانه بهداشت بزین',
                'unit_type_id' => 9,
                'lat' => 35.693389,
                'lng' => 48.643694,
                'boundary_id' => null
            ],
            [
                'id' => 494,
                'region_id' => 5,
                'parent_id' => 90,
                'name' => 'فوریت کهلا',
                'unit_type_id' => 9,
                'lat' => 35.666222,
                'lng' => 48.68175,
                'boundary_id' => null
            ],
            [
                'id' => 495,
                'region_id' => 5,
                'parent_id' => 90,
                'name' => 'خانه بهداشت حی',
                'unit_type_id' => 9,
                'lat' => 35.655222,
                'lng' => 48.712194,
                'boundary_id' => null
            ],
            [
                'id' => 496,
                'region_id' => 5,
                'parent_id' => 90,
                'name' => 'خانه بهداشت شرور',
                'unit_type_id' => 9,
                'lat' => 35.711889,
                'lng' => 48.594944,
                'boundary_id' => null
            ],
            [
                'id' => 497,
                'region_id' => 5,
                'parent_id' => 90,
                'name' => 'خانه بهداشت کهلا',
                'unit_type_id' => 9,
                'lat' => 35.667778,
                'lng' => 48.679056,
                'boundary_id' => null
            ],
            [
                'id' => 498,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت آغوزلو',
                'unit_type_id' => 9,
                'lat' => 35.865222,
                'lng' => 48.527389,
                'boundary_id' => null
            ],
            [
                'id' => 499,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت بیگم آغا',
                'unit_type_id' => 9,
                'lat' => 35.912306,
                'lng' => 48.485694,
                'boundary_id' => null
            ],
            [
                'id' => 500,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت پیرمرزبان',
                'unit_type_id' => 9,
                'lat' => 35.859833,
                'lng' => 48.552944,
                'boundary_id' => null
            ],
            [
                'id' => 501,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت حسین آباد',
                'unit_type_id' => 9,
                'lat' => 35.909417,
                'lng' => 48.635222,
                'boundary_id' => null
            ],
            [
                'id' => 502,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت کوسج آباد',
                'unit_type_id' => 9,
                'lat' => 35.913111,
                'lng' => 48.669167,
                'boundary_id' => null
            ],
            [
                'id' => 503,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت هیرآباد',
                'unit_type_id' => 9,
                'lat' => 35.880833,
                'lng' => 48.675306,
                'boundary_id' => null
            ],
            [
                'id' => 504,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت گوگجه ییلاق',
                'unit_type_id' => 9,
                'lat' => 35.928694,
                'lng' => 48.566583,
                'boundary_id' => null
            ],
            [
                'id' => 505,
                'region_id' => 5,
                'parent_id' => 197,
                'name' => 'خانه بهداشت قلعلی',
                'unit_type_id' => 9,
                'lat' => 35.943306,
                'lng' => 48.550472,
                'boundary_id' => null
            ],
            [
                'id' => 506,
                'region_id' => 5,
                'parent_id' => 91,
                'name' => 'خانه بهداشت توزلو',
                'unit_type_id' => 9,
                'lat' => 36.278965,
                'lng' => 47.939115,
                'boundary_id' => null
            ],
            [
                'id' => 507,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه آزاد سفلي زنجان',
                'unit_type_id' => 9,
                'lat' => 36.530805,
                'lng' => 48.539382,
                'boundary_id' => null
            ],
            [
                'id' => 508,
                'region_id' => 5,
                'parent_id' => 91,
                'name' => 'خانه بهداشت استرود',
                'unit_type_id' => 9,
                'lat' => 35.702111,
                'lng' => 48.558472,
                'boundary_id' => null
            ],
            [
                'id' => 509,
                'region_id' => 5,
                'parent_id' => 91,
                'name' => 'خانه بهداشت غلام ویس',
                'unit_type_id' => 9,
                'lat' => 35.626528,
                'lng' => 48.52675,
                'boundary_id' => null
            ],
            [
                'id' => 510,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه دواسب زنجان',
                'unit_type_id' => 9,
                'lat' => 36.703763,
                'lng' => 48.558965,
                'boundary_id' => null
            ],
            [
                'id' => 511,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه ديزج بالا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.599188,
                'lng' => 48.445406,
                'boundary_id' => null
            ],
            [
                'id' => 512,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه رامين زنجان',
                'unit_type_id' => 9,
                'lat' => 36.557217,
                'lng' => 48.556534,
                'boundary_id' => null
            ],
            [
                'id' => 513,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه سارمساقلو زنجان',
                'unit_type_id' => 9,
                'lat' => 36.757436,
                'lng' => 48.371554,
                'boundary_id' => null
            ],
            [
                'id' => 514,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه قره تپه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.589286,
                'lng' => 48.516212,
                'boundary_id' => null
            ],
            [
                'id' => 515,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه قينرجه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.504413,
                'lng' => 48.531262,
                'boundary_id' => null
            ],
            [
                'id' => 516,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه محسن آباد زنجان',
                'unit_type_id' => 9,
                'lat' => 36.676532,
                'lng' => 48.258181,
                'boundary_id' => null
            ],
            [
                'id' => 517,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه همايون زنجان',
                'unit_type_id' => 9,
                'lat' => 36.752298,
                'lng' => 48.485369,
                'boundary_id' => null
            ],
            [
                'id' => 518,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه والارود زنجان',
                'unit_type_id' => 9,
                'lat' => 36.709805,
                'lng' => 48.367217,
                'boundary_id' => null
            ],
            [
                'id' => 519,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه پايين کوه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.709805,
                'lng' => 48.367217,
                'boundary_id' => null
            ],
            [
                'id' => 520,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه گوگجه قيا زنجان',
                'unit_type_id' => 9,
                'lat' => 36.516311,
                'lng' => 48.108566,
                'boundary_id' => null
            ],
            [
                'id' => 521,
                'region_id' => 5,
                'parent_id' => 105,
                'name' => 'خانه بهداشت زواجر',
                'unit_type_id' => 9,
                'lat' => 36.155528,
                'lng' => 48.588167,
                'boundary_id' => null
            ],
            [
                'id' => 522,
                'region_id' => 5,
                'parent_id' => 105,
                'name' => 'خانه بهداشت زرند',
                'unit_type_id' => 9,
                'lat' => 36.162583,
                'lng' => 48.517167,
                'boundary_id' => null
            ],
            [
                'id' => 523,
                'region_id' => 5,
                'parent_id' => 105,
                'name' => 'خانه بهداشت ارقین',
                'unit_type_id' => 9,
                'lat' => 36.166722,
                'lng' => 48.545444,
                'boundary_id' => null
            ],
            [
                'id' => 524,
                'region_id' => 5,
                'parent_id' => 105,
                'name' => 'خانه بهداشت نهروان',
                'unit_type_id' => 9,
                'lat' => 36.194167,
                'lng' => 48.527833,
                'boundary_id' => null
            ],
            [
                'id' => 525,
                'region_id' => 5,
                'parent_id' => 105,
                'name' => 'خانه بهداشت مهدی آباد',
                'unit_type_id' => 9,
                'lat' => 36.179167,
                'lng' => 48.454417,
                'boundary_id' => null
            ],
            [
                'id' => 526,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت ارقین بلاغ',
                'unit_type_id' => 9,
                'lat' => 35.807028,
                'lng' => 48.389167,
                'boundary_id' => null
            ],
            [
                'id' => 527,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت سرئین',
                'unit_type_id' => 9,
                'lat' => 36.356469,
                'lng' => 48.333925,
                'boundary_id' => null
            ],
            [
                'id' => 528,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت قزلبلاغ',
                'unit_type_id' => 9,
                'lat' => 35.816611,
                'lng' => 48.363472,
                'boundary_id' => null
            ],
            [
                'id' => 529,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت شهیدآباد',
                'unit_type_id' => 9,
                'lat' => 35.780222,
                'lng' => 48.49825,
                'boundary_id' => null
            ],
            [
                'id' => 530,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت قره محمد',
                'unit_type_id' => 9,
                'lat' => 35.723972,
                'lng' => 48.497222,
                'boundary_id' => null
            ],
            [
                'id' => 531,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت قره ولی',
                'unit_type_id' => 9,
                'lat' => 35.838611,
                'lng' => 48.313694,
                'boundary_id' => null
            ],
            [
                'id' => 532,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت قشقجه',
                'unit_type_id' => 9,
                'lat' => 35.790194,
                'lng' => 48.43575,
                'boundary_id' => null
            ],
            [
                'id' => 533,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'خانه بهداشت زرین رود',
                'unit_type_id' => 9,
                'lat' => 35.758694,
                'lng' => 48.48025,
                'boundary_id' => null
            ],
            [
                'id' => 534,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت نهاویس',
                'unit_type_id' => 9,
                'lat' => 36.258833,
                'lng' => 48.456639,
                'boundary_id' => null
            ],
            [
                'id' => 535,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت خوش',
                'unit_type_id' => 9,
                'lat' => 36.269833,
                'lng' => 48.468833,
                'boundary_id' => null
            ],
            [
                'id' => 536,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت چنگور',
                'unit_type_id' => 9,
                'lat' => 36.2315,
                'lng' => 48.504694,
                'boundary_id' => null
            ],
            [
                'id' => 537,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت ذوینق',
                'unit_type_id' => 9,
                'lat' => 36.2285,
                'lng' => 48.475861,
                'boundary_id' => null
            ],
            [
                'id' => 538,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت مجیدآباد',
                'unit_type_id' => 9,
                'lat' => 36.2315,
                'lng' => 48.600611,
                'boundary_id' => null
            ],
            [
                'id' => 539,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'خانه بهداشت خنداب',
                'unit_type_id' => 9,
                'lat' => 36.263792,
                'lng' => 48.493824,
                'boundary_id' => null
            ],
            [
                'id' => 540,
                'region_id' => 5,
                'parent_id' => 121,
                'name' => 'پایگاه سهرورد',
                'unit_type_id' => 9,
                'lat' => 36.072,
                'lng' => 48.443,
                'boundary_id' => null
            ],
            [
                'id' => 541,
                'region_id' => 5,
                'parent_id' => 121,
                'name' => 'خانه بهداشت مادآباد',
                'unit_type_id' => 9,
                'lat' => 36.13175,
                'lng' => 48.457139,
                'boundary_id' => null
            ],
            [
                'id' => 542,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'کوسه لر',
                'unit_type_id' => 9,
                'lat' => 36.498684,
                'lng' => 48.359027,
                'boundary_id' => null
            ],
            [
                'id' => 543,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'احمدکندی',
                'unit_type_id' => 9,
                'lat' => 36.499391,
                'lng' => 48.331325,
                'boundary_id' => null
            ],
            [
                'id' => 544,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'اوچ تپه',
                'unit_type_id' => 9,
                'lat' => 36.524527,
                'lng' => 48.296944,
                'boundary_id' => null
            ],
            [
                'id' => 545,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'قره سعید',
                'unit_type_id' => 9,
                'lat' => 36.479828,
                'lng' => 48.371429,
                'boundary_id' => null
            ],
            [
                'id' => 546,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'خانجین',
                'unit_type_id' => 9,
                'lat' => 36.41833,
                'lng' => 48.250644,
                'boundary_id' => null
            ],
            [
                'id' => 547,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'خانقاه',
                'unit_type_id' => 9,
                'lat' => 36.378666,
                'lng' => 48.234116,
                'boundary_id' => null
            ],
            [
                'id' => 548,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'قمچقای',
                'unit_type_id' => 9,
                'lat' => 36.371833,
                'lng' => 48.202005,
                'boundary_id' => null
            ],
            [
                'id' => 549,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'قلعه',
                'unit_type_id' => 9,
                'lat' => 36.449581,
                'lng' => 48.782768,
                'boundary_id' => null
            ],
            [
                'id' => 550,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'ویک',
                'unit_type_id' => 9,
                'lat' => 36.259466,
                'lng' => 48.828263,
                'boundary_id' => null
            ],
            [
                'id' => 551,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'کبودگنبد',
                'unit_type_id' => 9,
                'lat' => 36.385278,
                'lng' => 48.738028,
                'boundary_id' => null
            ],
            [
                'id' => 552,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'چشمه سار',
                'unit_type_id' => 9,
                'lat' => 36.359902,
                'lng' => 48.731634,
                'boundary_id' => null
            ],
            [
                'id' => 553,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'چپدره',
                'unit_type_id' => 9,
                'lat' => 36.379487,
                'lng' => 48.701432,
                'boundary_id' => null
            ],
            [
                'id' => 554,
                'region_id' => 8,
                'parent_id' => 153,
                'name' => 'سنبل آباد',
                'unit_type_id' => 9,
                'lat' => 36.436856,
                'lng' => 48.8801,
                'boundary_id' => null
            ],
            [
                'id' => 555,
                'region_id' => 8,
                'parent_id' => 154,
                'name' => 'ویر',
                'unit_type_id' => 9,
                'lat' => 36.348873,
                'lng' => 48.843702,
                'boundary_id' => null
            ],
            [
                'id' => 556,
                'region_id' => 8,
                'parent_id' => 153,
                'name' => 'بوئین',
                'unit_type_id' => 9,
                'lat' => 36.515292,
                'lng' => 48.900919,
                'boundary_id' => null
            ],
            [
                'id' => 557,
                'region_id' => 8,
                'parent_id' => 153,
                'name' => 'حسین آباد',
                'unit_type_id' => 9,
                'lat' => 36.408845,
                'lng' => 48.879158,
                'boundary_id' => null
            ],
            [
                'id' => 558,
                'region_id' => 8,
                'parent_id' => 154,
                'name' => 'ترکانده',
                'unit_type_id' => 9,
                'lat' => 36.327858,
                'lng' => 48.807068,
                'boundary_id' => null
            ],
            [
                'id' => 559,
                'region_id' => 8,
                'parent_id' => 153,
                'name' => 'دوسنگان',
                'unit_type_id' => 9,
                'lat' => 36.493897,
                'lng' => 48.937241,
                'boundary_id' => null
            ],
            [
                'id' => 560,
                'region_id' => 8,
                'parent_id' => 154,
                'name' => 'قیاسیه',
                'unit_type_id' => 9,
                'lat' => 36.369427,
                'lng' => 48.809794,
                'boundary_id' => null
            ],
            [
                'id' => 561,
                'region_id' => 8,
                'parent_id' => 153,
                'name' => 'والایش',
                'unit_type_id' => 9,
                'lat' => 36.462245,
                'lng' => 48.98436,
                'boundary_id' => null
            ],
            [
                'id' => 562,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'گوزلدره',
                'unit_type_id' => 9,
                'lat' => 36.488602,
                'lng' => 48.630545,
                'boundary_id' => null
            ],
            [
                'id' => 563,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'خرمدرق',
                'unit_type_id' => 9,
                'lat' => 36.443574,
                'lng' => 48.610151,
                'boundary_id' => null
            ],
            [
                'id' => 564,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'سبزدرق',
                'unit_type_id' => 9,
                'lat' => 36.47942,
                'lng' => 48.569189,
                'boundary_id' => null
            ],
            [
                'id' => 565,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'ساریجالو',
                'unit_type_id' => 9,
                'lat' => 36.489933,
                'lng' => 48.691438,
                'boundary_id' => null
            ],
            [
                'id' => 566,
                'region_id' => 8,
                'parent_id' => 156,
                'name' => 'خیرآباد',
                'unit_type_id' => 9,
                'lat' => 36.512597,
                'lng' => 48.781569,
                'boundary_id' => null
            ],
            [
                'id' => 567,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'شکورآباد',
                'unit_type_id' => 9,
                'lat' => 36.443613,
                'lng' => 48.680138,
                'boundary_id' => null
            ],
            [
                'id' => 568,
                'region_id' => 8,
                'parent_id' => 155,
                'name' => 'سلمانکندی',
                'unit_type_id' => 9,
                'lat' => 36.451393,
                'lng' => 48.663667,
                'boundary_id' => null
            ],
            [
                'id' => 569,
                'region_id' => 8,
                'parent_id' => 156,
                'name' => 'یوسف آباد',
                'unit_type_id' => 9,
                'lat' => 36.541614,
                'lng' => 48.739788,
                'boundary_id' => null
            ],
            [
                'id' => 570,
                'region_id' => 8,
                'parent_id' => 157,
                'name' => 'قره بلاغ',
                'unit_type_id' => 9,
                'lat' => 36.492836,
                'lng' => 48.800717,
                'boundary_id' => null
            ],
            [
                'id' => 571,
                'region_id' => 8,
                'parent_id' => 156,
                'name' => 'سرخه دیزج',
                'unit_type_id' => 9,
                'lat' => 36.591011,
                'lng' => 48.854865,
                'boundary_id' => null
            ],
            [
                'id' => 572,
                'region_id' => 8,
                'parent_id' => 156,
                'name' => 'مشک آباد',
                'unit_type_id' => 9,
                'lat' => 36.50961,
                'lng' => 48.751045,
                'boundary_id' => null
            ],
            [
                'id' => 573,
                'region_id' => 8,
                'parent_id' => 157,
                'name' => 'المکی',
                'unit_type_id' => 9,
                'lat' => 36.48101,
                'lng' => 48.760999,
                'boundary_id' => null
            ],
            [
                'id' => 574,
                'region_id' => 8,
                'parent_id' => 157,
                'name' => 'کاکاآباد',
                'unit_type_id' => 9,
                'lat' => 36.547893,
                'lng' => 48.861507,
                'boundary_id' => null
            ],
            [
                'id' => 575,
                'region_id' => 8,
                'parent_id' => 157,
                'name' => 'ونونان',
                'unit_type_id' => 9,
                'lat' => 36.611946,
                'lng' => 48.933353,
                'boundary_id' => null
            ],
            [
                'id' => 576,
                'region_id' => 8,
                'parent_id' => 157,
                'name' => 'آقزوج',
                'unit_type_id' => 9,
                'lat' => 36.580142,
                'lng' => 48.939637,
                'boundary_id' => null
            ],
            [
                'id' => 577,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت چتز',
                'unit_type_id' => 9,
                'lat' => 36.622814,
                'lng' => 47.850114,
                'boundary_id' => null
            ],
            [
                'id' => 578,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'فنوش آباد',
                'unit_type_id' => 9,
                'lat' => 36.169802,
                'lng' => 49.192211,
                'boundary_id' => null
            ],
            [
                'id' => 579,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'اسپاس',
                'unit_type_id' => 9,
                'lat' => 36.160694,
                'lng' => 49.106056,
                'boundary_id' => null
            ],
            [
                'id' => 580,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'کینه ورس',
                'unit_type_id' => 9,
                'lat' => 36.156667,
                'lng' => 49.057389,
                'boundary_id' => null
            ],
            [
                'id' => 581,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'میموندره',
                'unit_type_id' => 9,
                'lat' => 36.133751,
                'lng' => 49.135944,
                'boundary_id' => null
            ],
            [
                'id' => 582,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'کوی نیک',
                'unit_type_id' => 9,
                'lat' => 36.092251,
                'lng' => 49.165667,
                'boundary_id' => null
            ],
            [
                'id' => 583,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'چالچوق',
                'unit_type_id' => 9,
                'lat' => 36.113255,
                'lng' => 49.080733,
                'boundary_id' => null
            ],
            [
                'id' => 584,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت آقجه گنبد',
                'unit_type_id' => 9,
                'lat' => 35.7985,
                'lng' => 48.219194,
                'boundary_id' => null
            ],
            [
                'id' => 585,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت قمچقای',
                'unit_type_id' => 9,
                'lat' => 35.812806,
                'lng' => 48.168861,
                'boundary_id' => null
            ],
            [
                'id' => 586,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت حصارسفلی',
                'unit_type_id' => 9,
                'lat' => 35.845583,
                'lng' => 48.287639,
                'boundary_id' => null
            ],
            [
                'id' => 587,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت سوله',
                'unit_type_id' => 9,
                'lat' => 35.867167,
                'lng' => 48.195722,
                'boundary_id' => null
            ],
            [
                'id' => 588,
                'region_id' => 3,
                'parent_id' => 160,
                'name' => 'ییلاق مرشون',
                'unit_type_id' => 9,
                'lat' => 36.303445,
                'lng' => 49.389587,
                'boundary_id' => null
            ],
            [
                'id' => 589,
                'region_id' => 3,
                'parent_id' => 160,
                'name' => 'قارلوق',
                'unit_type_id' => 9,
                'lat' => 36.227932,
                'lng' => 49.308817,
                'boundary_id' => null
            ],
            [
                'id' => 590,
                'region_id' => 3,
                'parent_id' => 161,
                'name' => 'نورین',
                'unit_type_id' => 9,
                'lat' => 36.110639,
                'lng' => 49.282083,
                'boundary_id' => null
            ],
            [
                'id' => 591,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت قویی',
                'unit_type_id' => 9,
                'lat' => 35.896833,
                'lng' => 48.280639,
                'boundary_id' => null
            ],
            [
                'id' => 592,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت مصرآباد',
                'unit_type_id' => 9,
                'lat' => 35.870194,
                'lng' => 48.164361,
                'boundary_id' => null
            ],
            [
                'id' => 593,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت نریمان قشلاق',
                'unit_type_id' => 9,
                'lat' => 35.830083,
                'lng' => 48.110778,
                'boundary_id' => null
            ],
            [
                'id' => 594,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت گرماب',
                'unit_type_id' => 9,
                'lat' => 35.847389,
                'lng' => 48.197389,
                'boundary_id' => null
            ],
            [
                'id' => 595,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'خانه بهداشت لاچوان',
                'unit_type_id' => 9,
                'lat' => 36.105472,
                'lng' => 48.622972,
                'boundary_id' => null
            ],
            [
                'id' => 596,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'خانه بهداشت نظرقلی',
                'unit_type_id' => 9,
                'lat' => 36.159278,
                'lng' => 48.668528,
                'boundary_id' => null
            ],
            [
                'id' => 597,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'خانه بهداشت پسکوهان',
                'unit_type_id' => 9,
                'lat' => 36.077611,
                'lng' => 48.539639,
                'boundary_id' => null
            ],
            [
                'id' => 598,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'خانه بهداشت گوندره',
                'unit_type_id' => 9,
                'lat' => 36.149806,
                'lng' => 48.630111,
                'boundary_id' => null
            ],
            [
                'id' => 599,
                'region_id' => 3,
                'parent_id' => 163,
                'name' => 'قمچ آباد',
                'unit_type_id' => 9,
                'lat' => 36.080251,
                'lng' => 49.329639,
                'boundary_id' => null
            ],
            [
                'id' => 600,
                'region_id' => 3,
                'parent_id' => 163,
                'name' => 'حصار قاجار',
                'unit_type_id' => 9,
                'lat' => 36.066663,
                'lng' => 49.341462,
                'boundary_id' => null
            ],
            [
                'id' => 601,
                'region_id' => 3,
                'parent_id' => 163,
                'name' => 'توده بین',
                'unit_type_id' => 9,
                'lat' => 35.994472,
                'lng' => 49.304472,
                'boundary_id' => null
            ],
            [
                'id' => 602,
                'region_id' => 7,
                'parent_id' => 21,
                'name' => 'خانه بهداشت حسن آباد',
                'unit_type_id' => 9,
                'lat' => 36.835479,
                'lng' => 47.408674,
                'boundary_id' => null
            ],
            [
                'id' => 603,
                'region_id' => 3,
                'parent_id' => 165,
                'name' => 'ازناب',
                'unit_type_id' => 9,
                'lat' => 36.056905,
                'lng' => 49.214056,
                'boundary_id' => null
            ],
            [
                'id' => 604,
                'region_id' => 3,
                'parent_id' => 165,
                'name' => 'درسجین',
                'unit_type_id' => 9,
                'lat' => 36.021206,
                'lng' => 49.238231,
                'boundary_id' => null
            ],
            [
                'id' => 605,
                'region_id' => 3,
                'parent_id' => 165,
                'name' => 'خلیفه حصار',
                'unit_type_id' => 9,
                'lat' => 36.018472,
                'lng' => 49.210556,
                'boundary_id' => null
            ],
            [
                'id' => 606,
                'region_id' => 3,
                'parent_id' => 166,
                'name' => 'چنگ الماس',
                'unit_type_id' => 9,
                'lat' => 36.005667,
                'lng' => 49.092444,
                'boundary_id' => null
            ],
            [
                'id' => 607,
                'region_id' => 3,
                'parent_id' => 166,
                'name' => 'ایوانک',
                'unit_type_id' => 9,
                'lat' => 36.017194,
                'lng' => 49.083806,
                'boundary_id' => null
            ],
            [
                'id' => 608,
                'region_id' => 3,
                'parent_id' => 166,
                'name' => 'ینگی کند',
                'unit_type_id' => 9,
                'lat' => 35.925944,
                'lng' => 49.046194,
                'boundary_id' => null
            ],
            [
                'id' => 609,
                'region_id' => 3,
                'parent_id' => 166,
                'name' => 'دولت آباد',
                'unit_type_id' => 9,
                'lat' => 35.978111,
                'lng' => 49.131806,
                'boundary_id' => null
            ],
            [
                'id' => 610,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'قفس آباد',
                'unit_type_id' => 9,
                'lat' => 36.143544,
                'lng' => 48.944256,
                'boundary_id' => null
            ],
            [
                'id' => 611,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'زره باش',
                'unit_type_id' => 9,
                'lat' => 36.154389,
                'lng' => 48.993806,
                'boundary_id' => null
            ],
            [
                'id' => 612,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'قزلجه',
                'unit_type_id' => 9,
                'lat' => 36.097417,
                'lng' => 48.994167,
                'boundary_id' => null
            ],
            [
                'id' => 613,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'عباس آباد سفلی',
                'unit_type_id' => 9,
                'lat' => 36.077501,
                'lng' => 48.996222,
                'boundary_id' => null
            ],
            [
                'id' => 614,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'امیربستاق',
                'unit_type_id' => 9,
                'lat' => 36.058778,
                'lng' => 49.023861,
                'boundary_id' => null
            ],
            [
                'id' => 615,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'چشین',
                'unit_type_id' => 9,
                'lat' => 36.048306,
                'lng' => 49.063472,
                'boundary_id' => null
            ],
            [
                'id' => 616,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'خوشنام',
                'unit_type_id' => 9,
                'lat' => 35.985501,
                'lng' => 48.958749,
                'boundary_id' => null
            ],
            [
                'id' => 617,
                'region_id' => 3,
                'parent_id' => 167,
                'name' => 'نایجوک',
                'unit_type_id' => 9,
                'lat' => 35.950944,
                'lng' => 48.967556,
                'boundary_id' => null
            ],
            [
                'id' => 618,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'انذر',
                'unit_type_id' => 9,
                'lat' => 37.012228,
                'lng' => 48.737603,
                'boundary_id' => null
            ],
            [
                'id' => 619,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'ولیس',
                'unit_type_id' => 9,
                'lat' => 37.045325,
                'lng' => 48.626844,
                'boundary_id' => null
            ],
            [
                'id' => 620,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'کره مالیم',
                'unit_type_id' => 9,
                'lat' => 37.036703,
                'lng' => 48.670117,
                'boundary_id' => null
            ],
            [
                'id' => 621,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'کلئیم',
                'unit_type_id' => 9,
                'lat' => 37.078316,
                'lng' => 48.650917,
                'boundary_id' => null
            ],
            [
                'id' => 622,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'کلوچ',
                'unit_type_id' => 9,
                'lat' => 37.077529,
                'lng' => 48.635195,
                'boundary_id' => null
            ],
            [
                'id' => 623,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'گوهر',
                'unit_type_id' => 9,
                'lat' => 37.026489,
                'lng' => 48.713358,
                'boundary_id' => null
            ],
            [
                'id' => 624,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'الزین',
                'unit_type_id' => 9,
                'lat' => 36.819272,
                'lng' => 48.984947,
                'boundary_id' => null
            ],
            [
                'id' => 625,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'تسکین',
                'unit_type_id' => 9,
                'lat' => 36.818264,
                'lng' => 49.010864,
                'boundary_id' => null
            ],
            [
                'id' => 626,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'تشویر',
                'unit_type_id' => 9,
                'lat' => 36.788089,
                'lng' => 49.003506,
                'boundary_id' => null
            ],
            [
                'id' => 627,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'زرده',
                'unit_type_id' => 9,
                'lat' => 36.762561,
                'lng' => 48.977595,
                'boundary_id' => null
            ],
            [
                'id' => 628,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'قارقلی چم',
                'unit_type_id' => 9,
                'lat' => 36.801224,
                'lng' => 49.060578,
                'boundary_id' => null
            ],
            [
                'id' => 629,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'هندی کندی',
                'unit_type_id' => 9,
                'lat' => 36.775272,
                'lng' => 49.056847,
                'boundary_id' => null
            ],
            [
                'id' => 630,
                'region_id' => 9,
                'parent_id' => 169,
                'name' => 'گیلانکشه',
                'unit_type_id' => 9,
                'lat' => 36.753644,
                'lng' => 49.072328,
                'boundary_id' => null
            ],
            [
                'id' => 631,
                'region_id' => 9,
                'parent_id' => 170,
                'name' => 'جزلاندشت',
                'unit_type_id' => 9,
                'lat' => 37.015086,
                'lng' => 48.786969,
                'boundary_id' => null
            ],
            [
                'id' => 632,
                'region_id' => 9,
                'parent_id' => 170,
                'name' => 'درام',
                'unit_type_id' => 9,
                'lat' => 37.02122,
                'lng' => 48.775038,
                'boundary_id' => null
            ],
            [
                'id' => 633,
                'region_id' => 9,
                'parent_id' => 170,
                'name' => 'سیاه ورود',
                'unit_type_id' => 9,
                'lat' => 37.118508,
                'lng' => 48.872275,
                'boundary_id' => null
            ],
            [
                'id' => 634,
                'region_id' => 9,
                'parent_id' => 170,
                'name' => 'پاوه رود',
                'unit_type_id' => 9,
                'lat' => 37.060851,
                'lng' => 48.713062,
                'boundary_id' => null
            ],
            [
                'id' => 635,
                'region_id' => 9,
                'parent_id' => 171,
                'name' => 'ارشت',
                'unit_type_id' => 9,
                'lat' => 36.950053,
                'lng' => 48.831064,
                'boundary_id' => null
            ],
            [
                'id' => 636,
                'region_id' => 9,
                'parent_id' => 171,
                'name' => 'جیا',
                'unit_type_id' => 9,
                'lat' => 36.929083,
                'lng' => 48.801025,
                'boundary_id' => null
            ],
            [
                'id' => 637,
                'region_id' => 9,
                'parent_id' => 171,
                'name' => 'دهنه',
                'unit_type_id' => 9,
                'lat' => 36.878453,
                'lng' => 48.878911,
                'boundary_id' => null
            ],
            [
                'id' => 638,
                'region_id' => 9,
                'parent_id' => 171,
                'name' => 'خانه سانسیز',
                'unit_type_id' => 9,
                'lat' => 36.912625,
                'lng' => 48.859375,
                'boundary_id' => null
            ],
            [
                'id' => 639,
                'region_id' => 9,
                'parent_id' => 171,
                'name' => 'کهیا',
                'unit_type_id' => 9,
                'lat' => 36.911261,
                'lng' => 48.82281,
                'boundary_id' => null
            ],
            [
                'id' => 640,
                'region_id' => 9,
                'parent_id' => 172,
                'name' => 'انارستان',
                'unit_type_id' => 9,
                'lat' => 36.839297,
                'lng' => 49.104225,
                'boundary_id' => null
            ],
            [
                'id' => 641,
                'region_id' => 9,
                'parent_id' => 172,
                'name' => 'زهترآباد',
                'unit_type_id' => 9,
                'lat' => 36.846808,
                'lng' => 49.037136,
                'boundary_id' => null
            ],
            [
                'id' => 642,
                'region_id' => 9,
                'parent_id' => 172,
                'name' => 'خانه گیلوان',
                'unit_type_id' => 9,
                'lat' => 36.784783,
                'lng' => 49.133086,
                'boundary_id' => null
            ],
            [
                'id' => 643,
                'region_id' => 9,
                'parent_id' => 173,
                'name' => 'خانه دستجرده',
                'unit_type_id' => 9,
                'lat' => 36.850398,
                'lng' => 48.944006,
                'boundary_id' => null
            ],
            [
                'id' => 644,
                'region_id' => 9,
                'parent_id' => 173,
                'name' => 'سرخه دیزج',
                'unit_type_id' => 9,
                'lat' => 36.813653,
                'lng' => 48.94595,
                'boundary_id' => null
            ],
            [
                'id' => 645,
                'region_id' => 9,
                'parent_id' => 173,
                'name' => 'قاضی بلاغی',
                'unit_type_id' => 9,
                'lat' => 36.844636,
                'lng' => 48.922669,
                'boundary_id' => null
            ],
            [
                'id' => 646,
                'region_id' => 9,
                'parent_id' => 173,
                'name' => 'ونیسر',
                'unit_type_id' => 9,
                'lat' => 36.880418,
                'lng' => 48.89461,
                'boundary_id' => null
            ],
            [
                'id' => 647,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'آستاکل',
                'unit_type_id' => 9,
                'lat' => 36.938654,
                'lng' => 48.88827,
                'boundary_id' => null
            ],
            [
                'id' => 648,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'ده بهار',
                'unit_type_id' => 9,
                'lat' => 36.895411,
                'lng' => 49.027708,
                'boundary_id' => null
            ],
            [
                'id' => 649,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'قانقلی چای',
                'unit_type_id' => 9,
                'lat' => 36.869875,
                'lng' => 48.976625,
                'boundary_id' => null
            ],
            [
                'id' => 650,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'کهریز',
                'unit_type_id' => 9,
                'lat' => 36.874856,
                'lng' => 49.00721,
                'boundary_id' => null
            ],
            [
                'id' => 651,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'کوهکن',
                'unit_type_id' => 9,
                'lat' => 36.900261,
                'lng' => 48.946208,
                'boundary_id' => null
            ],
            [
                'id' => 652,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'هارون آباد',
                'unit_type_id' => 9,
                'lat' => 36.915791,
                'lng' => 48.90664,
                'boundary_id' => null
            ],
            [
                'id' => 653,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'هزارود',
                'unit_type_id' => 9,
                'lat' => 36.966359,
                'lng' => 48.919257,
                'boundary_id' => null
            ],
            [
                'id' => 654,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'ایچ',
                'unit_type_id' => 9,
                'lat' => 36.928333,
                'lng' => 48.780385,
                'boundary_id' => null
            ],
            [
                'id' => 655,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'جزلا',
                'unit_type_id' => 9,
                'lat' => 36.987795,
                'lng' => 48.741019,
                'boundary_id' => null
            ],
            [
                'id' => 656,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'زاچکان',
                'unit_type_id' => 9,
                'lat' => 36.950872,
                'lng' => 48.704867,
                'boundary_id' => null
            ],
            [
                'id' => 657,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'شیت',
                'unit_type_id' => 9,
                'lat' => 36.977756,
                'lng' => 48.702383,
                'boundary_id' => null
            ],
            [
                'id' => 658,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'ولیدر',
                'unit_type_id' => 9,
                'lat' => 36.970264,
                'lng' => 48.630607,
                'boundary_id' => null
            ],
            [
                'id' => 659,
                'region_id' => 4,
                'parent_id' => 150,
                'name' => 'جوقین2',
                'unit_type_id' => 9,
                'lat' => 36.362266,
                'lng' => 48.307429,
                'boundary_id' => null
            ],
            [
                'id' => 660,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 0,
                'unit_type_id' => 9,
                'lat' => 5,
                'lng' => 5,
                'boundary_id' => null
            ],
            [
                'id' => 661,
                'region_id' => 2,
                'parent_id' => 29,
                'name' => 'خانه بهداشت قارلوق',
                'unit_type_id' => 9,
                'lat' => 37.060625,
                'lng' => 48.27048,
                'boundary_id' => null
            ],
            [
                'id' => 662,
                'region_id' => 3,
                'parent_id' => 163,
                'name' => 'قروه',
                'unit_type_id' => 9,
                'lat' => 36.064472,
                'lng' => 49.388111,
                'boundary_id' => null
            ],
            [
                'id' => 663,
                'region_id' => 6,
                'parent_id' => 204,
                'name' => 'خانه باغدره',
                'unit_type_id' => 9,
                'lat' => 36.216941,
                'lng' => 48.98863,
                'boundary_id' => null
            ],
            [
                'id' => 664,
                'region_id' => 6,
                'parent_id' => 205,
                'name' => 'خانه اینجلین',
                'unit_type_id' => 9,
                'lat' => 36.383916,
                'lng' => 49.204487,
                'boundary_id' => null
            ],
            [
                'id' => 665,
                'region_id' => 9,
                'parent_id' => 168,
                'name' => 'سرخه میشه',
                'unit_type_id' => 9,
                'lat' => 37.010949,
                'lng' => 48.652042,
                'boundary_id' => null
            ],
            [
                'id' => 666,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'پایگاه ضمیمه سجاس',
                'unit_type_id' => 9,
                'lat' => 36.238,
                'lng' => 48.552778,
                'boundary_id' => null
            ],
            [
                'id' => 667,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'پايگاه سلامت غير ضميمه سجاس',
                'unit_type_id' => 9,
                'lat' => 36.238,
                'lng' => 48.552778,
                'boundary_id' => null
            ],
            [
                'id' => 668,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'پایگاه ضمیمه شماره دو',
                'unit_type_id' => 9,
                'lat' => 36.121717,
                'lng' => 48.589771,
                'boundary_id' => null
            ],
            [
                'id' => 669,
                'region_id' => 7,
                'parent_id' => 23,
                'name' => 'سهند سفلی',
                'unit_type_id' => 9,
                'lat' => 36.774899,
                'lng' => 47.541243,
                'boundary_id' => null
            ],
            [
                'id' => 670,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'خانه بهداشت ایلی بلاغ',
                'unit_type_id' => 9,
                'lat' => 36.665998,
                'lng' => 47.748334,
                'boundary_id' => null
            ],
            [
                'id' => 671,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'خانه بهداشت شیخلر',
                'unit_type_id' => 9,
                'lat' => 36.59786,
                'lng' => 47.485156,
                'boundary_id' => null
            ],
            [
                'id' => 672,
                'region_id' => 7,
                'parent_id' => 24,
                'name' => 'خانه بهداشت خانکندی',
                'unit_type_id' => 9,
                'lat' => 36.536671,
                'lng' => 47.804119,
                'boundary_id' => null
            ],
            [
                'id' => 673,
                'region_id' => 2,
                'parent_id' => 32,
                'name' => 'خانه گل بلاغی زنجان',
                'unit_type_id' => 9,
                'lat' => 36.632058,
                'lng' => 48.091398,
                'boundary_id' => null
            ],
            [
                'id' => 674,
                'region_id' => 2,
                'parent_id' => 45,
                'name' => 'خانه بهداشت ولیاران',
                'unit_type_id' => 9,
                'lat' => 36.955015,
                'lng' => 48.402077,
                'boundary_id' => null
            ],
            [
                'id' => 675,
                'region_id' => 2,
                'parent_id' => null,
                'name' => 'خانه نيماور',
                'unit_type_id' => 9,
                'lat' => 36.554277,
                'lng' => 48.679098,
                'boundary_id' => null
            ],
            [
                'id' => 676,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'خانه سهله زنجان',
                'unit_type_id' => 9,
                'lat' => 36.567326,
                'lng' => 48.491765,
                'boundary_id' => null
            ],
            [
                'id' => 677,
                'region_id' => 2,
                'parent_id' => null,
                'name' => 'خانه میرجان زنجان',
                'unit_type_id' => 9,
                'lat' => 36.742755,
                'lng' => 48.001077,
                'boundary_id' => null
            ],
            [
                'id' => 678,
                'region_id' => 2,
                'parent_id' => 40,
                'name' => 'خانه قلعه زنجان',
                'unit_type_id' => 9,
                'lat' => 36.806013,
                'lng' => 48.786158,
                'boundary_id' => null
            ],
            [
                'id' => 679,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'خانه بهداشت قره ولی',
                'unit_type_id' => 9,
                'lat' => 35.838611,
                'lng' => 48.313611,
                'boundary_id' => null
            ],
            [
                'id' => 680,
                'region_id' => 2,
                'parent_id' => 54,
                'name' => 'خانه بهداشت قره چریان',
                'unit_type_id' => 9,
                'lat' => 36.889288,
                'lng' => 48.263177,
                'boundary_id' => null
            ],
            [
                'id' => 681,
                'region_id' => 5,
                'parent_id' => 121,
                'name' => 'خانه بهداشت ابدال لر',
                'unit_type_id' => 9,
                'lat' => 36.102083,
                'lng' => 48.357083,
                'boundary_id' => null
            ],
            [
                'id' => 682,
                'region_id' => 3,
                'parent_id' => 42,
                'name' => 'پایگاه 1 هیدج',
                'unit_type_id' => 8,
                'lat' => 36.258683,
                'lng' => 49.135911,
                'boundary_id' => null
            ],
            [
                'id' => 683,
                'region_id' => 3,
                'parent_id' => 47,
                'name' => 'پایگاه ضمیمه شماره 5',
                'unit_type_id' => 8,
                'lat' => 36.139722,
                'lng' => 49.205611,
                'boundary_id' => null
            ],
            [
                'id' => 684,
                'region_id' => 3,
                'parent_id' => 48,
                'name' => 'پایگاه ضمیمه اعلایی',
                'unit_type_id' => 8,
                'lat' => 36.149809,
                'lng' => 49.216738,
                'boundary_id' => null
            ],
            [
                'id' => 685,
                'region_id' => 3,
                'parent_id' => 158,
                'name' => 'پایگاه ضمیمه حسین آباد',
                'unit_type_id' => 8,
                'lat' => 36.149953,
                'lng' => 49.198257,
                'boundary_id' => null
            ],
            [
                'id' => 686,
                'region_id' => 3,
                'parent_id' => 160,
                'name' => 'پایگاه ضمیمه',
                'unit_type_id' => 8,
                'lat' => 36.159001,
                'lng' => 49.237861,
                'boundary_id' => null
            ],
            [
                'id' => 687,
                'region_id' => 3,
                'parent_id' => 160,
                'name' => 'پایگاه غ.ضمیمه شماره 2',
                'unit_type_id' => 8,
                'lat' => 36.168833,
                'lng' => 49.245167,
                'boundary_id' => null
            ],
            [
                'id' => 688,
                'region_id' => 3,
                'parent_id' => 160,
                'name' => 'پایگاه غ.ضمیمه شماره3',
                'unit_type_id' => 8,
                'lat' => 36.154972,
                'lng' => 49.234139,
                'boundary_id' => null
            ],
            [
                'id' => 689,
                'region_id' => 3,
                'parent_id' => 161,
                'name' => 'پایگاه شریف آباد',
                'unit_type_id' => 8,
                'lat' => 36.135051,
                'lng' => 49.268392,
                'boundary_id' => null
            ],
            [
                'id' => 690,
                'region_id' => 8,
                'parent_id' => 152,
                'name' => 'پایگاه ضمیمه سلطانیه',
                'unit_type_id' => 8,
                'lat' => 36.435535,
                'lng' => 48.788475,
                'boundary_id' => null
            ],
            [
                'id' => 691,
                'region_id' => 6,
                'parent_id' => 17,
                'name' => 'ضمیمه 4',
                'unit_type_id' => 8,
                'lat' => 36.21409,
                'lng' => 49.192014,
                'boundary_id' => null
            ],
            [
                'id' => 692,
                'region_id' => 3,
                'parent_id' => 49,
                'name' => 'پایگاه ضمیمه 17شهریور',
                'unit_type_id' => 8,
                'lat' => 36.140139,
                'lng' => 49.217639,
                'boundary_id' => null
            ],
            [
                'id' => 693,
                'region_id' => 5,
                'parent_id' => 184,
                'name' => 'پایگاه ضمیمه (مرکز شماره 3)',
                'unit_type_id' => 8,
                'lat' => 36.110389,
                'lng' => 48.58675,
                'boundary_id' => null
            ],
            [
                'id' => 694,
                'region_id' => 5,
                'parent_id' => 183,
                'name' => 'پایگاه ضمیمه (مرکز شماره 1)',
                'unit_type_id' => 8,
                'lat' => 36.121444,
                'lng' => 48.589778,
                'boundary_id' => null
            ],
            [
                'id' => 695,
                'region_id' => 5,
                'parent_id' => 159,
                'name' => 'پایگاه ضمیمه گرماب',
                'unit_type_id' => 8,
                'lat' => 35.847389,
                'lng' => 48.197389,
                'boundary_id' => null
            ],
            [
                'id' => 696,
                'region_id' => 5,
                'parent_id' => 118,
                'name' => 'پایگاه غیر ضمیمه زرین رود',
                'unit_type_id' => 10,
                'lat' => 35.758694,
                'lng' => 48.48025,
                'boundary_id' => null
            ],
            [
                'id' => 697,
                'region_id' => 4,
                'parent_id' => 200,
                'name' => 'پایگاه سلامت حلب',
                'unit_type_id' => 10,
                'lat' => 36.296242,
                'lng' => 48.063898,
                'boundary_id' => null
            ],
            [
                'id' => 698,
                'region_id' => 6,
                'parent_id' => 14,
                'name' => 'غیر ضمیمه گلدشت',
                'unit_type_id' => 10,
                'lat' => 36.196557,
                'lng' => 49.21361,
                'boundary_id' => null
            ],
            [
                'id' => 699,
                'region_id' => 6,
                'parent_id' => 16,
                'name' => 'غیرضمیمه حبیب اکبری',
                'unit_type_id' => 10,
                'lat' => 36.208983,
                'lng' => 49.204809,
                'boundary_id' => null
            ],
            [
                'id' => 700,
                'region_id' => 6,
                'parent_id' => 15,
                'name' => 'غیر ضمیمه وصال',
                'unit_type_id' => 10,
                'lat' => 36.201984,
                'lng' => 49.177035,
                'boundary_id' => null
            ],
            [
                'id' => 701,
                'region_id' => 7,
                'parent_id' => 30,
                'name' => 'پایگاه ماهنشان',
                'unit_type_id' => 10,
                'lat' => 36.742472,
                'lng' => 47.668891,
                'boundary_id' => null
            ],
            [
                'id' => 702,
                'region_id' => 7,
                'parent_id' => 31,
                'name' => 'پایگاه دندی',
                'unit_type_id' => 10,
                'lat' => 36.551856,
                'lng' => 47.619683,
                'boundary_id' => null
            ],
            [
                'id' => 703,
                'region_id' => 3,
                'parent_id' => 33,
                'name' => 'پایگاه صائین قلعه',
                'unit_type_id' => 10,
                'lat' => 36.302562,
                'lng' => 49.073208,
                'boundary_id' => null
            ],
            [
                'id' => 704,
                'region_id' => 3,
                'parent_id' => 42,
                'name' => 'پایگاه 2 هیدج',
                'unit_type_id' => 10,
                'lat' => 36.250385,
                'lng' => 49.123781,
                'boundary_id' => null
            ],
            [
                'id' => 705,
                'region_id' => 3,
                'parent_id' => 49,
                'name' => 'غیرضمیمه شماره 2',
                'unit_type_id' => 10,
                'lat' => 36.151728,
                'lng' => 49.230779,
                'boundary_id' => null
            ],
            [
                'id' => 706,
                'region_id' => 3,
                'parent_id' => 49,
                'name' => 'غیرضمیمه شماره3',
                'unit_type_id' => 10,
                'lat' => 36.145904,
                'lng' => 49.223411,
                'boundary_id' => null
            ],
            [
                'id' => 707,
                'region_id' => 2,
                'parent_id' => 56,
                'name' => 'پایگاه شماره 2 مرکز 10 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.67953,
                'lng' => 48.50572,
                'boundary_id' => null
            ],
            [
                'id' => 708,
                'region_id' => 2,
                'parent_id' => 56,
                'name' => 'پایگاه شماره 3 مرکز 10 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.67814,
                'lng' => 48.50843,
                'boundary_id' => null
            ],
            [
                'id' => 709,
                'region_id' => 2,
                'parent_id' => 56,
                'name' => 'پایگاه شماره يک مرکز 10 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.67707,
                'lng' => 48.501445,
                'boundary_id' => null
            ],
            [
                'id' => 710,
                'region_id' => 2,
                'parent_id' => 57,
                'name' => 'پایگاه شماره 2 مرکز 11 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.667054,
                'lng' => 48.536944,
                'boundary_id' => null
            ],
            [
                'id' => 711,
                'region_id' => 2,
                'parent_id' => 57,
                'name' => 'پایگاه شماره يک مرکز 11 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.666023,
                'lng' => 48.547055,
                'boundary_id' => null
            ],
            [
                'id' => 712,
                'region_id' => 2,
                'parent_id' => 58,
                'name' => 'پایگاه شماره 2 مرکز 12 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.700259,
                'lng' => 48.494291,
                'boundary_id' => null
            ],
            [
                'id' => 713,
                'region_id' => 2,
                'parent_id' => 58,
                'name' => 'پایگاه شماره يک مرکز 12 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.683401,
                'lng' => 48.49439,
                'boundary_id' => null
            ],
            [
                'id' => 714,
                'region_id' => 2,
                'parent_id' => 59,
                'name' => 'پایگاه شماره يک مرکز 13 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.664443,
                'lng' => 48.468547,
                'boundary_id' => null
            ],
            [
                'id' => 715,
                'region_id' => 2,
                'parent_id' => 60,
                'name' => 'پایگاه شماره 2 مرکز 14 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.684583,
                'lng' => 48.508185,
                'boundary_id' => null
            ],
            [
                'id' => 716,
                'region_id' => 2,
                'parent_id' => 60,
                'name' => 'پایگاه شماره يک مرکز 14 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.691349,
                'lng' => 48.509548,
                'boundary_id' => null
            ],
            [
                'id' => 717,
                'region_id' => 2,
                'parent_id' => 61,
                'name' => 'پایگاه شماره 2 مرکز 15 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.687788,
                'lng' => 48.454937,
                'boundary_id' => null
            ],
            [
                'id' => 718,
                'region_id' => 2,
                'parent_id' => 61,
                'name' => 'پایگاه شماره يک مرکز 15 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.696642,
                'lng' => 48.451873,
                'boundary_id' => null
            ],
            [
                'id' => 719,
                'region_id' => 2,
                'parent_id' => 62,
                'name' => 'پایگاه شماره 2 مرکز 16 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.69844,
                'lng' => 48.462222,
                'boundary_id' => null
            ],
            [
                'id' => 720,
                'region_id' => 2,
                'parent_id' => 62,
                'name' => 'پایگاه شماره يک مرکز 16 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.701371,
                'lng' => 48.480452,
                'boundary_id' => null
            ],
            [
                'id' => 721,
                'region_id' => 2,
                'parent_id' => 63,
                'name' => 'پایگاه شماره 2 مرکز 17 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.682677,
                'lng' => 48.5265,
                'boundary_id' => null
            ],
            [
                'id' => 722,
                'region_id' => 2,
                'parent_id' => 63,
                'name' => 'پایگاه شماره يک مرکز 17 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.683899,
                'lng' => 48.530393,
                'boundary_id' => null
            ],
            [
                'id' => 723,
                'region_id' => 2,
                'parent_id' => 64,
                'name' => 'پایگاه شماره 2 مرکز 18 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.675015,
                'lng' => 48.546717,
                'boundary_id' => null
            ],
            [
                'id' => 724,
                'region_id' => 2,
                'parent_id' => 64,
                'name' => 'پایگاه شماره يک مرکز 18 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.670712,
                'lng' => 48.553828,
                'boundary_id' => null
            ],
            [
                'id' => 725,
                'region_id' => 2,
                'parent_id' => 65,
                'name' => 'پایگاه شماره 2 مرکز 2 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.67186,
                'lng' => 48.45953,
                'boundary_id' => null
            ],
            [
                'id' => 726,
                'region_id' => 2,
                'parent_id' => 65,
                'name' => 'پایگاه شماره يک مرکز 2 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.674129,
                'lng' => 48.461686,
                'boundary_id' => null
            ],
            [
                'id' => 727,
                'region_id' => 2,
                'parent_id' => 65,
                'name' => 'پایگاه شماره 3 مرکز 2 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.682559,
                'lng' => 48.441006,
                'boundary_id' => null
            ],
            [
                'id' => 728,
                'region_id' => 2,
                'parent_id' => 66,
                'name' => 'پایگاه شماره يک مرکز 3 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.675922,
                'lng' => 48.485179,
                'boundary_id' => null
            ],
            [
                'id' => 729,
                'region_id' => 2,
                'parent_id' => 67,
                'name' => 'پایگاه شماره 2 مرکز 4 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.65944,
                'lng' => 48.505937,
                'boundary_id' => null
            ],
            [
                'id' => 730,
                'region_id' => 2,
                'parent_id' => 67,
                'name' => 'پایگاه شماره 3 مرکز 4 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.645139,
                'lng' => 48.548434,
                'boundary_id' => null
            ],
            [
                'id' => 731,
                'region_id' => 2,
                'parent_id' => 67,
                'name' => 'پایگاه شماره يک مرکز 4 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.663887,
                'lng' => 48.481865,
                'boundary_id' => null
            ],
            [
                'id' => 732,
                'region_id' => 2,
                'parent_id' => 68,
                'name' => 'پایگاه شماره 2 مرکز 6 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.68802,
                'lng' => 48.4744,
                'boundary_id' => null
            ],
            [
                'id' => 733,
                'region_id' => 2,
                'parent_id' => 68,
                'name' => 'پایگاه شماره 3 مرکز 6 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.68686,
                'lng' => 48.46701,
                'boundary_id' => null
            ],
            [
                'id' => 734,
                'region_id' => 2,
                'parent_id' => 68,
                'name' => 'پایگاه شماره 4 مرکز 6 زنجان',
                'unit_type_id' => 10,
                'lat' => 4.872048,
                'lng' => -13.31543,
                'boundary_id' => null
            ],
            [
                'id' => 735,
                'region_id' => 2,
                'parent_id' => 68,
                'name' => 'پایگاه شماره يک مرکز 6 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.690783,
                'lng' => 48.46932,
                'boundary_id' => null
            ],
            [
                'id' => 736,
                'region_id' => 2,
                'parent_id' => 69,
                'name' => 'پایگاه شماره يک مرکز 7 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.671247,
                'lng' => 48.499471,
                'boundary_id' => null
            ],
            [
                'id' => 737,
                'region_id' => 2,
                'parent_id' => 71,
                'name' => 'پایگاه شماره 2 مرکز 8 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.680948,
                'lng' => 48.482175,
                'boundary_id' => null
            ],
            [
                'id' => 738,
                'region_id' => 2,
                'parent_id' => 71,
                'name' => 'پایگاه شماره يک مرکز 8 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.679158,
                'lng' => 48.469555,
                'boundary_id' => null
            ],
            [
                'id' => 739,
                'region_id' => 2,
                'parent_id' => 72,
                'name' => 'پایگاه شماره 2 مرکز 9 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.668134,
                'lng' => 48.534689,
                'boundary_id' => null
            ],
            [
                'id' => 740,
                'region_id' => 2,
                'parent_id' => 72,
                'name' => 'پایگاه شماره 3 مرکز 9 زنجان(حذف شده)',
                'unit_type_id' => 10,
                'lat' => 36.670773,
                'lng' => 48.511785,
                'boundary_id' => null
            ],
            [
                'id' => 741,
                'region_id' => 2,
                'parent_id' => 72,
                'name' => 'پایگاه شماره يک مرکز 9 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.661286,
                'lng' => 48.514365,
                'boundary_id' => null
            ],
            [
                'id' => 742,
                'region_id' => 2,
                'parent_id' => 73,
                'name' => 'پایگاه شماره يک (ضميمه) زنجان',
                'unit_type_id' => 10,
                'lat' => 36.669237,
                'lng' => 48.471369,
                'boundary_id' => null
            ],
            [
                'id' => 743,
                'region_id' => 2,
                'parent_id' => 74,
                'name' => 'پایگاه شماره يک مرکز 5 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.672787,
                'lng' => 48.481576,
                'boundary_id' => null
            ],
            [
                'id' => 744,
                'region_id' => 5,
                'parent_id' => 120,
                'name' => 'پایگاه سجاس',
                'unit_type_id' => 10,
                'lat' => 36.238,
                'lng' => 48.552778,
                'boundary_id' => null
            ],
            [
                'id' => 745,
                'region_id' => 4,
                'parent_id' => 199,
                'name' => 'پایگاه سلامت زرین آباد',
                'unit_type_id' => 10,
                'lat' => 36.42149,
                'lng' => 48.271694,
                'boundary_id' => null
            ],
            [
                'id' => 746,
                'region_id' => 5,
                'parent_id' => 162,
                'name' => 'پايگاه سلامت ضميمه (مرکز شماره2)',
                'unit_type_id' => 10,
                'lat' => 36.120222,
                'lng' => 48.596222,
                'boundary_id' => null
            ],
            [
                'id' => 747,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'پایگاه سلامت آب بر شماره 1',
                'unit_type_id' => 10,
                'lat' => 36.927389,
                'lng' => 48.952778,
                'boundary_id' => null
            ],
            [
                'id' => 748,
                'region_id' => 5,
                'parent_id' => 183,
                'name' => 'پایگاه غیر ضمیمه(مرکز شماره1)',
                'unit_type_id' => 10,
                'lat' => 36.130667,
                'lng' => 48.594167,
                'boundary_id' => null
            ],
            [
                'id' => 749,
                'region_id' => 5,
                'parent_id' => 184,
                'name' => 'پایگاه غیر ضمیمه (مرکز شماره3)',
                'unit_type_id' => 10,
                'lat' => 36.110417,
                'lng' => 48.577917,
                'boundary_id' => null
            ],
            [
                'id' => 750,
                'region_id' => 5,
                'parent_id' => null,
                'name' => 'پایگاه شماره 2',
                'unit_type_id' => 10,
                'lat' => 36.120214,
                'lng' => 48.596474,
                'boundary_id' => null
            ],
            [
                'id' => 751,
                'region_id' => 9,
                'parent_id' => 177,
                'name' => 'پایگاه سلامت چورزق',
                'unit_type_id' => 10,
                'lat' => 36.989927,
                'lng' => 48.781998,
                'boundary_id' => null
            ],
            [
                'id' => 752,
                'region_id' => 9,
                'parent_id' => 176,
                'name' => 'پایگاه سلامت آب بر شماره 2',
                'unit_type_id' => 10,
                'lat' => 36.92085,
                'lng' => 48.966717,
                'boundary_id' => null
            ],
            [
                'id' => 753,
                'region_id' => 6,
                'parent_id' => 203,
                'name' => 'پایگاه شماره 2 شهری',
                'unit_type_id' => 10,
                'lat' => 36.206488,
                'lng' => 49.189388,
                'boundary_id' => null
            ],
            [
                'id' => 754,
                'region_id' => 2,
                'parent_id' => 207,
                'name' => 'پایگاه دیابت',
                'unit_type_id' => 10,
                'lat' => 36.670838,
                'lng' => 48.480808,
                'boundary_id' => null
            ],
            [
                'id' => 755,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'پایگاه نوربهار1',
                'unit_type_id' => 10,
                'lat' => 35.936722,
                'lng' => 48.790183,
                'boundary_id' => null
            ],
            [
                'id' => 756,
                'region_id' => 5,
                'parent_id' => 87,
                'name' => 'پایگاه نوربهار2',
                'unit_type_id' => 10,
                'lat' => 35.939083,
                'lng' => 48.795222,
                'boundary_id' => null
            ],
            [
                'id' => 757,
                'region_id' => 2,
                'parent_id' => 69,
                'name' => 'پایگاه شماره 2 مرکز 7 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.672583,
                'lng' => 48.513154,
                'boundary_id' => null
            ],
            [
                'id' => 758,
                'region_id' => 2,
                'parent_id' => null,
                'name' => 'پایگاه شماره يک مرکز 19 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.682861,
                'lng' => 48.440168,
                'boundary_id' => null
            ],
            [
                'id' => 759,
                'region_id' => 2,
                'parent_id' => null,
                'name' => 'پایگاه شماره 2 مرکز 19 زنجان',
                'unit_type_id' => 10,
                'lat' => 36.687722,
                'lng' => 48.455068,
                'boundary_id' => null
            ],
            [
                'id' => 760,
                'region_id' => 6,
                'parent_id' => 18,
                'name' => 'مرکز 5',
                'unit_type_id' => 10,
                'lat' => 36.193967,
                'lng' => 49.186564,
                'boundary_id' => null
            ],
            [
                'id' => 761,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'اسدآباد',
                'unit_type_id' => 12,
                'lat' => 36.209539,
                'lng' => 49.324264,
                'boundary_id' => null
            ],
            [
                'id' => 762,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'برزابیل',
                'unit_type_id' => 12,
                'lat' => 36.190849,
                'lng' => 49.36303,
                'boundary_id' => null
            ],
            [
                'id' => 763,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'علی آباد کرد',
                'unit_type_id' => 12,
                'lat' => 36.124349,
                'lng' => 49.382747,
                'boundary_id' => null
            ],
            [
                'id' => 764,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'قره تپه',
                'unit_type_id' => 12,
                'lat' => 36.152833,
                'lng' => 49.377182,
                'boundary_id' => null
            ],
            [
                'id' => 765,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'قویجوق خانی',
                'unit_type_id' => 12,
                'lat' => 36.140353,
                'lng' => 49.35555,
                'boundary_id' => null
            ],
            [
                'id' => 766,
                'region_id' => 3,
                'parent_id' => 589,
                'name' => 'گلجه',
                'unit_type_id' => 12,
                'lat' => 36.18317,
                'lng' => 49.41304,
                'boundary_id' => null
            ],
            [
                'id' => 767,
                'region_id' => 3,
                'parent_id' => 588,
                'name' => 'حسین آباد قارقالو',
                'unit_type_id' => 12,
                'lat' => 36.314248,
                'lng' => 49.308403,
                'boundary_id' => null
            ],
            [
                'id' => 768,
                'region_id' => 3,
                'parent_id' => 588,
                'name' => 'حصار عبدالکریم',
                'unit_type_id' => 12,
                'lat' => 36.290263,
                'lng' => 49.325213,
                'boundary_id' => null
            ],
            [
                'id' => 769,
                'region_id' => 3,
                'parent_id' => 588,
                'name' => 'داودگر',
                'unit_type_id' => 12,
                'lat' => 36.331598,
                'lng' => 49.280599,
                'boundary_id' => null
            ],
            [
                'id' => 770,
                'region_id' => 3,
                'parent_id' => 588,
                'name' => 'کله خانه',
                'unit_type_id' => 12,
                'lat' => 36.310586,
                'lng' => 49.344504,
                'boundary_id' => null
            ],
            [
                'id' => 771,
                'region_id' => 3,
                'parent_id' => 603,
                'name' => 'کلنگرز',
                'unit_type_id' => 12,
                'lat' => 36.034281,
                'lng' => 49.177873,
                'boundary_id' => null
            ],
            [
                'id' => 772,
                'region_id' => 3,
                'parent_id' => 603,
                'name' => 'رازمجین',
                'unit_type_id' => 12,
                'lat' => 36.034281,
                'lng' => 49.177873,
                'boundary_id' => null
            ],
            [
                'id' => 773,
                'region_id' => 3,
                'parent_id' => 605,
                'name' => 'ارکین',
                'unit_type_id' => 12,
                'lat' => 36.001549,
                'lng' => 49.18289,
                'boundary_id' => null
            ],
            [
                'id' => 774,
                'region_id' => 3,
                'parent_id' => 604,
                'name' => 'آقجه کند',
                'unit_type_id' => 12,
                'lat' => 36.001753,
                'lng' => 49.225818,
                'boundary_id' => null
            ],
            [
                'id' => 775,
                'region_id' => 3,
                'parent_id' => 604,
                'name' => 'شورین',
                'unit_type_id' => 12,
                'lat' => 36.003299,
                'lng' => 49.261148,
                'boundary_id' => null
            ],
            [
                'id' => 776,
                'region_id' => 3,
                'parent_id' => 609,
                'name' => 'شکر چشمه',
                'unit_type_id' => 12,
                'lat' => 35.979272,
                'lng' => 49.163778,
                'boundary_id' => null
            ],
            [
                'id' => 777,
                'region_id' => 3,
                'parent_id' => 608,
                'name' => 'چشمه بار',
                'unit_type_id' => 12,
                'lat' => 35.952652,
                'lng' => 49.039511,
                'boundary_id' => null
            ],
            [
                'id' => 778,
                'region_id' => 3,
                'parent_id' => 608,
                'name' => 'آغور',
                'unit_type_id' => 12,
                'lat' => 35.886098,
                'lng' => 49.058194,
                'boundary_id' => null
            ],
            [
                'id' => 779,
                'region_id' => 3,
                'parent_id' => 614,
                'name' => 'گل تپه',
                'unit_type_id' => 12,
                'lat' => 36.037742,
                'lng' => 48.994363,
                'boundary_id' => null
            ],
            [
                'id' => 780,
                'region_id' => 3,
                'parent_id' => 616,
                'name' => 'گلی',
                'unit_type_id' => 12,
                'lat' => 36.013429,
                'lng' => 48.931098,
                'boundary_id' => null
            ],
            [
                'id' => 781,
                'region_id' => 3,
                'parent_id' => 613,
                'name' => 'عباس آباد علیا',
                'unit_type_id' => 12,
                'lat' => 36.081967,
                'lng' => 48.977498,
                'boundary_id' => null
            ],
            [
                'id' => 782,
                'region_id' => 3,
                'parent_id' => 612,
                'name' => 'قره آغاج',
                'unit_type_id' => 12,
                'lat' => 36.121362,
                'lng' => 49.01546,
                'boundary_id' => null
            ]
        ];

        // وارد کردن داده‌ها
        foreach ($units as $unit) {
            Unit::create($unit);
        }
    }
}
