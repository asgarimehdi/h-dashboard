<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Province;
use App\Models\County;
use App\Models\Unit;
use App\Models\UnitType;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // دریافت تمام استان‌ها و نوع واحدها
        $provinces = Province::all();
        $unitTypes = UnitType::all()->pluck('id', 'name')->toArray();

        // 1. ایجاد وزارت‌خانه (سطح ریشه)
        $ministry = Unit::create([
            'name' => 'وزارت بهداشت',
            'unit_type_id' => $unitTypes['وزارت خانه'],
            'parent_id' => null,
            'province_id' => null,
            'county_id' => null,
            'description' => 'وزارت بهداشت مرکزی',
        ]);

        // 2. ایجاد دانشگاه‌های علوم پزشکی برای هر استان
        foreach ($provinces as $province) {
            $medicalUniversities = ["دانشگاه علوم پزشکی $province->name"];
            if (in_array($province->name, ['تهران'])) {
                $medicalUniversities[] = "دانشگاه علوم پزشکی دوم $province->name";
            }

            foreach ($medicalUniversities as $uniName) {
                $university = Unit::create([
                    'name' => $uniName,
                    'unit_type_id' => $unitTypes['دانشگاه علوم پزشکی'],
                    'parent_id' => $ministry->id,
                    'province_id' => $province->id,
                    'county_id' => null,
                    'description' => "دانشگاه علوم پزشکی در $province->name",
                ]);

                // 3. ایجاد معاونت‌ها و مرکز بهداشت استان
                $subUnits = [
                    'معاونت بهداشت' => 'وظیفه نظارت بر بهداشت عمومی',
                    'معاونت درمان' => 'وظیفه مدیریت درمان',
                    'معاونت آموزش' => 'وظیفه آموزش پزشکی',
                    'معاونت توسعه' => 'وظیفه توسعه زیرساخت‌ها',
                    'مرکز بهداشت استان' => 'مرکز بهداشت استانی',
                ];

                foreach ($subUnits as $subUnitName => $description) {
                    $uniqueSubUnitName = "$subUnitName $uniName";
                    Unit::create([
                        'name' => $uniqueSubUnitName,
                        'unit_type_id' => $unitTypes[$subUnitName],
                        'parent_id' => $university->id,
                        'province_id' => $province->id,
                        'county_id' => null,
                        'description' => $description,
                    ]);
                }

                // 4. دریافت شهرستان‌های استان و ایجاد شبکه بهداشت (حداکثر 2 مورد برای هر استان)
                $counties = County::where('province_id', $province->id)->take(2)->get();
                foreach ($counties as $county) {
                    $networkName = "شبکه بهداشت $county->name - $uniName";
                    $network = Unit::create([
                        'name' => $networkName,
                        'unit_type_id' => $unitTypes['شبکه بهداشت'],
                        'parent_id' => $university->id,
                        'province_id' => $province->id,
                        'county_id' => $county->id,
                        'description' => "شبکه بهداشت در $county->name",
                    ]);

                    // 5. ایجاد مرکز بهداشت شهرستان
                    $countyCenterName = "مرکز بهداشت $county->name - $networkName";
                    $countyCenter = Unit::create([
                        'name' => $countyCenterName,
                        'unit_type_id' => $unitTypes['مرکز بهداشت شهرستان'],
                        'parent_id' => $network->id,
                        'province_id' => $province->id,
                        'county_id' => $county->id,
                        'description' => "مرکز بهداشت شهرستان $county->name",
                    ]);

                    // 6. ایجاد مراکز خدمات جامع سلامت (هر نوع 3 مورد)
                    $comprehensiveCenters = [
                        'مرکز خدمات جامع سلامت شهری' => ['مرکز شهری 1', 'مرکز شهری 2', 'مرکز شهری 3'],
                        'مرکز خدمات جامع سلامت شهری روستایی' => ['مرکز شهری-روستایی 1', 'مرکز شهری-روستایی 2', 'مرکز شهری-روستایی 3'],
                        'مرکز خدمات جامع سلامت روستایی' => ['مرکز روستایی 1', 'مرکز روستایی 2', 'مرکز روستایی 3'],
                    ];

                    foreach ($comprehensiveCenters as $type => $names) {
                        foreach ($names as $name) {
                            $centerName = "$name $county->name - $uniName";
                            $center = Unit::create([
                                'name' => $centerName,
                                'unit_type_id' => $unitTypes[$type],
                                'parent_id' => $countyCenter->id,
                                'province_id' => $province->id,
                                'county_id' => $county->id,
                                'description' => "$type در $county->name",
                            ]);

                            // 7. ایجاد پایگاه سلامت (2 مورد برای مراکز شهری)
                            if ($type === 'مرکز خدمات جامع سلامت شهری') {
                                for ($i = 1; $i <= 2; $i++) {
                                    $healthBaseName = "پایگاه سلامت $i $name $county->name - $uniName";
                                    Unit::create([
                                        'name' => $healthBaseName,
                                        'unit_type_id' => $unitTypes['پایگاه سلامت'],
                                        'parent_id' => $center->id,
                                        'province_id' => $province->id,
                                        'county_id' => $county->id,
                                        'description' => "پایگاه سلامت در $name",
                                    ]);
                                }
                            }

                            // 8. ایجاد خانه بهداشت (2 مورد برای مراکز روستایی یا شهری-روستایی)
                            if (in_array($type, ['مرکز خدمات جامع سلامت شهری روستایی', 'مرکز خدمات جامع سلامت روستایی'])) {
                                for ($i = 1; $i <= 2; $i++) {
                                    $healthHouseName = "خانه بهداشت $i $name $county->name - $uniName";
                                    Unit::create([
                                        'name' => $healthHouseName,
                                        'unit_type_id' => $unitTypes['خانه بهداشت'],
                                        'parent_id' => $center->id,
                                        'province_id' => $province->id,
                                        'county_id' => $county->id,
                                        'description' => "خانه بهداشت در $name",
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
