<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\BoundarySeeder;
use Database\Seeders\PersonUserFromDeviceSeeder;
use Database\Seeders\RegionSeeder;
use Database\Seeders\UnitSeeder;
use Database\Seeders\UnitTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(PersonUserFromDeviceSeeder::class);

class PersonUserFromDeviceSeederTest extends TestCase
{
    use InteractsWithTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLookupTables();

        // BoundarySeeder با auto-increment و UnitSeeder/RegionSeeder با id صریح
        // insert می‌کنند، پس sequence ها باید از ۱ شروع شوند — sequence ها
        // غیر-تراکنشی‌اند و تست‌های قبلی جلو انداخته‌اند.
        foreach (['boundaries', 'unit_types', 'regions'] as $table) {
            DB::statement("SELECT setval('{$table}_id_seq', COALESCE((SELECT MAX(id) FROM {$table}), 1), false)");
        }

        // همان ترتیب DatabaseSeeder
        $this->seed(BoundarySeeder::class);
        $this->seed(UnitTypeSeeder::class);
        $this->seed(RegionSeeder::class);
        $this->seed(UnitSeeder::class);
    }

    public function test_device_units_are_declared_in_unit_seeder_with_type_and_county(): void
    {
        // نام واحد => نوع واحد مورد انتظار
        $expected = [
            'ستاد' => 'ستادی',
            'دبیرخانه' => 'ستادی',
            'بهورزی' => 'ستادی',
            'دفتر مدیریت' => 'ستادی', // در فایل دیتا بود ولی هرگز ساخته نشده بود
            'آی تی' => 'ستادی',
            'فوریت' => 'فوریت',
            'پایگاه فوریت چرگر' => 'فوریت',
            'مرکز سراج' => 'مرکز روان',
            'پایگاه غیر ضمیمه صائین قلعه' => 'پایگاه سلامت غیر ضمیمه',
        ];

        foreach ($expected as $unitName => $typeName) {
            $unit = Unit::where('name', $unitName)->first();
            $this->assertNotNull($unit, "واحد «{$unitName}» باید در UnitSeeder تعریف شده باشد");
            $this->assertEquals($typeName, $unit->unitType->name ?? null, "واحد «{$unitName}» باید نوع «{$typeName}» داشته باشد");
        }

        $this->assertSame(0, Unit::whereNull('unit_type_id')->count(), 'هیچ واحدی نباید بدون نوع واحد بماند');

        $this->assertEquals(
            ['وزارت بهداشت'],
            Unit::whereNull('region_id')->pluck('name')->all(),
            'فقط ریشه (وزارت بهداشت) نباید شهرستان داشته باشد'
        );

        $setad = Unit::where('name', 'ستاد')->first();
        $this->assertEquals('ابهر', $setad->region->name ?? null, 'شهرستان واحد ستاد باید ابهر باشد');
    }

    public function test_person_seeder_resolves_every_device_path_without_creating_units(): void
    {
        $unitsBefore = Unit::count();

        $this->seed(PersonUserFromDeviceSeeder::class);
        $this->assertSame(
            $unitsBefore,
            Unit::count(),
            'سیدر افراد نباید واحد جدیدی بسازد — همه مسیرهای فایل دیتا باید در UnitSeeder باشند'
        );

        // اجرای دوم هم نباید چیزی بسازد
        $this->seed(PersonUserFromDeviceSeeder::class);
        $this->assertSame($unitsBefore, Unit::count());
    }
}
